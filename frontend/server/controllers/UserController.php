<?php

/**
 *  UserController
 *
 * @author joemmanuel
 */
require_once 'SessionController.php';

class UserController extends Controller {
    public static $sendEmailOnVerify = true;
    public static $redirectOnVerify = true;
    public static $permissionKey = null;

    /**
     * Entry point for Create a User API
     *
     * @param Request $r
     * @return array
     * @throws InvalidDatabaseOperationException
     * @throws DuplicatedEntryInDatabaseException
     */
    public static function apiCreate(Request $r) {
        // Validate request
        Validators::isValidUsername($r['username'], 'username');

        Validators::isEmail($r['email'], 'email');

        // Check password
        $hashedPassword = null;
        if (!isset($r['ignore_password'])) {
            SecurityTools::testStrongPassword($r['password']);
            $hashedPassword = SecurityTools::hashString($r['password']);
        }

        // Does user or email already exists?
        try {
            $user = UsersDAO::FindByUsername($r['username']);
            $userByEmail = UsersDAO::FindByEmail($r['email']);
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        if (!is_null($userByEmail)) {
            throw new DuplicatedEntryInDatabaseException('mailInUse');
        }

        if (!is_null($user)) {
            throw new DuplicatedEntryInDatabaseException('usernameInUse');
        }

        // Prepare DAOs
        $user_data = array(
            'username' => $r['username'],
            'password' => $hashedPassword,
            'solved' => 0,
            'submissions' => 0,
            'verified' => 0,
            'verification_id' => self::randomString(50),
        );
        if (isset($r['name'])) {
            $user_data['name'] = $r['name'];
        }
        if (isset($r['facebook_user_id'])) {
            $user_data['facebook_user_id'] = $r['facebook_user_id'];
        }
        if (!is_null(self::$permissionKey) &&
            self::$permissionKey == $r['permission_key']) {
            $user_data['verified'] = 1;
        } elseif (OMEGAUP_VALIDATE_CAPTCHA) {
            // Validate captcha
            if (!isset($r['recaptcha'])) {
                throw new InvalidParameterException('parameterNotFound', 'recaptcha');
            }

            $url = 'https://www.google.com/recaptcha/api/siteverify';
            $data = array(
                'secret' => OMEGAUP_RECAPTCHA_SECRET,
                'response' => $r['recaptcha'],
                'remoteip' => $_SERVER['REMOTE_ADDR']);

            // use key 'http' even if you send the request to https://...
            $options = array(
                    'http' => array(
                        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                        'method'  => 'POST',
                        'content' => http_build_query($data),
                        ),
                    );
            $context  = stream_context_create($options);
            $result = file_get_contents($url, false, $context);

            if ($result === false) {
                self::$log->error('POST Request to Google Recaptcha failed.');
                throw new CaptchaVerificationFailedException();
            }

            $resultAsJson = json_decode($result, true);
            if (is_null($resultAsJson)) {
                self::$log->error('Captcha response was not a json');
                self::$log->error('Here is the result:' . $result);
                throw new CaptchaVerificationFailedException();
            }

            if (!(array_key_exists('success', $resultAsJson) && $resultAsJson['success'])) {
                self::$log->error('Captcha response said no');
                throw new CaptchaVerificationFailedException();
            }
        }

        $user = new Users($user_data);

        $email = new Emails(array(
            'email' => $r['email'],
        ));

        // Save objects into DB
        try {
            DAO::transBegin();

            UsersDAO::save($user);

            $email->setUserId($user->getUserId());
            EmailsDAO::save($email);

            $user->setMainEmailId($email->getEmailId());
            UsersDAO::save($user);

            DAO::transEnd();
        } catch (Exception $e) {
            DAO::transRollback();
            throw new InvalidDatabaseOperationException($e);
        }

        $r['user'] = $user;
        if (!$user->verified) {
            self::$log->info('User ' . $user->getUsername() . ' created, sending verification mail');

            self::sendVerificationEmail($r);
        } else {
            self::$log->info('User ' . $user->getUsername() . ' created, trusting e-mail');
        }

        return array(
            'status' => 'ok',
            'user_id' => $user->getUserId()
        );
    }

    /**
     * Registers the created user to Sendy
     *
     * @param Request $r
     */
    private static function registerToSendy(Users $user) {
        if (!OMEGAUP_EMAIL_SENDY_ENABLE) {
            return;
        }

        self::$log->info('Adding user to Sendy.');

        // Get email
        try {
            $email = EmailsDAO::getByPK($user->getMainEmailId());
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        //Subscribe
        $postdata = http_build_query(
            array(
                'name' => $user->username,
                'email' => $email->email,
                'list' => OMEGAUP_EMAIL_SENDY_LIST,
                'boolean' => 'true'
                )
        );
        $opts = array(
            'http' => array(
                'method'  => 'POST',
                'header'  => 'Content-type: application/x-www-form-urlencoded',
                'content' => $postdata)
        );

        $context  = stream_context_create($opts);
        $result = file_get_contents(OMEGAUP_EMAIL_SENDY_SUBSCRIBE_URL, false, $context);

        //check result and redirect
        if ($result) {
            self::$log->info('Success adding user to Sendy.');
        } else {
            self::$log->info('Failure adding user to Sendy.');
        }
        self::$log->info($result);
    }

    /**
     *
     * Description:
     *     Tests a if a password is valid for a given user.
     *
     * @param user_id
     * @param email
     * @param username
     * @param password
     *
     * */
    public function TestPassword(Request $r) {
        $user_id = $email = $username = $password = null;

        if (null != $r['user_id']) {
            $user_id = $r['user_id'];
        }

        if (null != $r['email']) {
            $email = $r['email'];
        }

        if (null != $r['username']) {
            $username = $r['username'];
        }

        if (null != $r['password']) {
            $password = $r['password'];
        }

        if (is_null($user_id) && is_null($email) && is_null($username)) {
            throw new ApiException('mustProvideUSerIdEmailOrUsername');
        }

        $vo_UserToTest = null;

        //find this user
        if (!is_null($user_id)) {
            $vo_UserToTest = UsersDAO::getByPK($user_id);
        } elseif (!is_null($email)) {
            $vo_UserToTest = $this->FindByEmail();
        } else {
            $vo_UserToTest = $this->FindByUserName();
        }

        if (is_null($vo_UserToTest)) {
            //user does not even exist
            return false;
        }

        if (strlen($vo_UserToTest->getPassword()) === 0) {
            throw new LoginDisabledException();
        }

        $newPasswordCheck = SecurityTools::compareHashedStrings(
            $password,
            $vo_UserToTest->getPassword()
        );

        // We are OK
        if ($newPasswordCheck === true) {
            return true;
        }
    }

    /**
     * Send the mail with verification link to the user in the Request
     *
     * @param Request $r
     * @throws InvalidDatabaseOperationException
     * @throws EmailVerificationSendException
     */
    private static function sendVerificationEmail(Request $r) {
        if (!OMEGAUP_EMAIL_SEND_EMAILS) {
            return;
        }

        try {
            $email = EmailsDAO::getByPK($r['user']->getMainEmailId());
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        self::$log->info('Sending email to user.');
        if (self::$sendEmailOnVerify) {
            $mail = new PHPMailer();
            $mail->IsSMTP();
            $mail->Host = OMEGAUP_EMAIL_SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Password = OMEGAUP_EMAIL_SMTP_PASSWORD;
            $mail->From = OMEGAUP_EMAIL_SMTP_FROM;
            $mail->Port = 465;
            $mail->SMTPSecure = 'ssl';
            $mail->Username = OMEGAUP_EMAIL_SMTP_FROM;

            $mail->FromName = OMEGAUP_EMAIL_SMTP_FROM;
            $mail->AddAddress($email->getEmail());
            $mail->isHTML(true);
            $mail->Subject = 'Bienvenido a Omegaup!';
            $mail->Body = 'Bienvenido a Omegaup! Por favor ingresa a la siguiente dirección para hacer login y verificar tu email: <a href="https://omegaup.com/api/user/verifyemail/id/' . $r['user']->getVerificationId() . '"> https://omegaup.com/api/user/verifyemail/id/' . $r['user']->getVerificationId() . '</a>';

            if (!$mail->Send()) {
                self::$log->error('Failed to send mail: ' . $mail->ErrorInfo);
                throw new EmailVerificationSendException();
            }
        }
    }

    /**
     * Check if email of user in request has been verified
     *
     * @param Request $r
     * @throws EmailNotVerifiedException
     */
    public static function checkEmailVerification(Request $r) {
        if (OMEGAUP_FORCE_EMAIL_VERIFICATION) {
            // Check if he has been verified
            if ($r['user']->getVerified() == '0') {
                self::$log->info('User not verified.');

                if ($r['user']->getVerificationId() == null) {
                    self::$log->info('User does not have verification id. Generating.');

                    try {
                        $r['user']->setVerificationId(self::randomString(50));
                        UsersDAO::save($r['user']);
                    } catch (Exception $e) {
                        // best effort, eat exception
                    }

                    self::sendVerificationEmail($r);
                }

                throw new EmailNotVerifiedException();
            } else {
                self::$log->info('User already verified.');
            }
        }
    }

    /**
     * Exposes API /user/login
     * Expects in request:
     * user
     * password
     *
     *
     * @param Request $r
     */
    public static function apiLogin(Request $r) {
        // Create a SessionController to perform login
        $sessionController = new SessionController();

        // Require the auth_token back
        $r['returnAuthToken'] = true;

        // Get auth_token
        $auth_token = $sessionController->NativeLogin($r);

        // If user was correctly logged in
        if ($auth_token !== false) {
            return array(
                'status' => 'ok',
                'auth_token' => $auth_token);
        } else {
            throw new InvalidCredentialsException();
        }
    }

    /**
     * Changes the password of a user
     *
     * @param Request $rﬁ
     * @return array
     * @throws ForbiddenAccessException
     */
    public static function apiChangePassword(Request $r) {
        if (OMEGAUP_LOCKDOWN) {
            throw new ForbiddenAccessException('lockdown');
        }

        self::authenticateRequest($r);

        $hashedPassword = null;
        if (isset($r['username']) &&
            ((!is_null(self::$permissionKey) && self::$permissionKey == $r['permission_key']) ||
            Authorization::IsSystemAdmin($r['current_user_id']))) {
            // System admin can force reset passwords for any user
            Validators::isStringNonEmpty($r['username'], 'username');

            try {
                $user = UsersDAO::FindByUsername($r['username']);

                if (is_null($user)) {
                    throw new NotFoundException('userNotExist');
                }
            } catch (Exception $e) {
                throw new InvalidDatabaseOperationException($e);
            }

            if (isset($r['password']) && $r['password'] != '') {
                SecurityTools::testStrongPassword($r['password']);
                $hashedPassword = SecurityTools::hashString($r['password']);
            }
        } else {
            $user = $r['current_user'];

            if ($user->getPassword() != null) {
                // Check the old password
                Validators::isStringNonEmpty($r['old_password'], 'old_password');

                $old_password_valid = SecurityTools::compareHashedStrings(
                    $r['old_password'],
                    $user->getPassword()
                );

                if ($old_password_valid === false) {
                    throw new InvalidParameterException('parameterInvalid', 'old_password');
                }
            }

            SecurityTools::testStrongPassword($r['password']);
            $hashedPassword = SecurityTools::hashString($r['password']);
        }

        $user->setPassword($hashedPassword);
        UsersDAO::save($user);

        return array('status' => 'ok');
    }

    /**
     * Verifies the user given its verification id
     *
     * @param Request $r
     * @return type
     * @throws ApiException
     * @throws InvalidDatabaseOperationException
     * @throws NotFoundException
     */
    public static function apiVerifyEmail(Request $r) {
        $user = null;

        // Admin can override verification by sending username
        if (isset($r['usernameOrEmail'])) {
            self::authenticateRequest($r);

            if (!Authorization::IsSystemAdmin($r['current_user_id'])) {
                throw new ForbiddenAccessException();
            }

            self::$log->info('Admin verifiying user...' . $r['usernameOrEmail']);

            Validators::isStringNonEmpty($r['usernameOrEmail'], 'usernameOrEmail');

            $user = self::resolveUser($r['usernameOrEmail']);

            self::$redirectOnVerify = false;
        } else {
            // Normal user verification path
            Validators::isStringNonEmpty($r['id'], 'id');

            try {
                $users = UsersDAO::search(new Users(array(
                                    'verification_id' => $r['id']
                                )));

                $user = (is_array($users) && count($users) > 0) ? $users[0] : null;
            } catch (Exception $e) {
                throw new InvalidDatabaseOperationException($e);
            }
        }

        if (is_null($user)) {
            throw new NotFoundException('verificationIdInvalid');
        }

        try {
            $user->setVerified(1);
            UsersDAO::save($user);
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        self::$log->info('User verification complete.');

        self::registerToSendy($user);

        if (self::$redirectOnVerify) {
            die(header('Location: /login/'));
        }
        return array('status' => 'ok');
    }

    /**
     * Given a username or a email, returns the user object
     *
     * @param type $userOrEmail
     * @return User
     * @throws ApiException
     * @throws InvalidDatabaseOperationException
     * @throws InvalidParameterException
     */
    public static function resolveUser($userOrEmail) {
        Validators::isStringNonEmpty($userOrEmail, 'Username or email not found');

        $user = null;

        try {
            if (!is_null($user = UsersDAO::FindByEmail($userOrEmail))
                    || !is_null($user = UsersDAO::FindByUsername($userOrEmail))) {
                return $user;
            } else {
                throw new NotFoundException('userOrMailNotFound');
            }
        } catch (ApiException $apiException) {
            throw $apiException;
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        return $user;
    }

    /**
     * Resets the password of the OMI user and adds the user to the private
     * contest.
     * If the user does not exists, we create him.
     *
     * @param Request $r
     * @param string $username
     * @param string $password
     */
    private static function omiPrepareUser(Request $r, $username, $password) {
        try {
            $user = UsersDAO::FindByUsername($username);
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        if (is_null($user)) {
            self::$log->info('Creating user: ' . $username);
            $createRequest = new Request(array(
                'username' => $username,
                'password' => $password,
                'email' => $username . '@omi.com',
                'permission_key' => $r['permission_key']
            ));

            UserController::$sendEmailOnVerify = false;
            self::apiCreate($createRequest);
            return true;
        } elseif (is_null($r['change_password']) || $r['change_password'] !== 'false') {
            if (!$user->getVerified()) {
                self::apiVerifyEmail(new Request(array(
                    'auth_token' => $r['auth_token'],
                    'usernameOrEmail' => $username
                )));
            }

            // Pwd changes are by default unless explictly disabled
            $resetRequest = new Request();
            $resetRequest['auth_token'] = $r['auth_token'];
            $resetRequest['username'] = $username;
            $resetRequest['password'] = $password;
            $resetRequest['permission_key'] = $r['permission_key'];
            self::apiChangePassword($resetRequest);
            return true;
        }

        return false;
    }

    /**
     *
     * @param Request $r
     * @return array
     * @throws ForbiddenAccessException
     */
    public static function apiGenerateOmiUsers(Request $r) {
        self::authenticateRequest($r);

        $response = array();

        $is_system_admin = Authorization::IsSystemAdmin($r['current_user_id']);
        if ($r['contest_type'] == 'OMI') {
            if ($r['current_user']->getUsername() != 'andreasantillana'
                && !$is_system_admin
            ) {
                throw new ForbiddenAccessException();
            }

            // Arreglo de estados de MX
            $keys = array(
                'OMI2016-AGU' => 4,
                'OMI2016-BCN' => 4,
                'OMI2016-BCS' => 4,
                'OMI2016-CAM' => 4,
                'OMI2016-CHH' => 4,
                'OMI2016-CHP' => 4,
                'OMI2016-CMX' => 4,
                'OMI2016-COA' => 4,
                'OMI2016-COL' => 4,
                'OMI2016-DUR' => 4,
                'OMI2016-GRO' => 4,
                'OMI2016-GUA' => 4,
                'OMI2016-HID' => 4,
                'OMI2016-JAL' => 4,
                'OMI2016-MEX' => 4,
                'OMI2016-MIC' => 4,
                'OMI2016-MOR' => 4,
                'OMI2016-NAY' => 4,
                'OMI2016-NLE' => 4,
                'OMI2016-OAX' => 4,
                'OMI2016-PUE' => 4,
                'OMI2016-QTO' => 4,
                'OMI2016-ROO' => 4,
                'OMI2016-SIN' => 4,
                'OMI2016-SLP' => 4,
                'OMI2016-SON' => 4,
                'OMI2016-TAB' => 4,
                'OMI2016-TAM' => 4,
                'OMI2016-TLA' => 4,
                'OMI2016-VER' => 8,
                'OMI2016-YUC' => 4,
                'OMI2016-ZAC' => 4,
                'OMI2016-INV' => 4,
            );
        } elseif ($r['contest_type'] == 'OMIP') {
            if ($r['current_user']->getUsername() != 'andreasantillana'
                && !$is_system_admin
            ) {
                throw new ForbiddenAccessException();
            }

            $keys = array(
                'OMIP2016' => 800,
            );
        } elseif ($r['contest_type'] == 'OMIS') {
            if ($r['current_user']->getUsername() != 'andreasantillana'
                && !$is_system_admin
            ) {
                throw new ForbiddenAccessException();
            }

            $keys = array(
                'OMIS2016' => 800,
            );
        } elseif ($r['contest_type'] == 'OMIPN') {
            if ($r['current_user']->getUsername() != 'andreasantillana'
                && !$is_system_admin
            ) {
                throw new ForbiddenAccessException();
            }

            $keys = array(
                'OMIP2016-AGU' => 4,
                'OMIP2016-BCN' => 4,
                'OMIP2016-BCS' => 4,
                'OMIP2016-CAM' => 4,
                'OMIP2016-CHH' => 4,
                'OMIP2016-CHP' => 4,
                'OMIP2016-CMX' => 4,
                'OMIP2016-COA' => 4,
                'OMIP2016-COL' => 4,
                'OMIP2016-DUR' => 4,
                'OMIP2016-GRO' => 4,
                'OMIP2016-GUA' => 4,
                'OMIP2016-HID' => 4,
                'OMIP2016-JAL' => 4,
                'OMIP2016-MEX' => 4,
                'OMIP2016-MIC' => 4,
                'OMIP2016-MOR' => 4,
                'OMIP2016-NAY' => 4,
                'OMIP2016-NLE' => 4,
                'OMIP2016-OAX' => 4,
                'OMIP2016-PUE' => 4,
                'OMIP2016-QTO' => 4,
                'OMIP2016-ROO' => 4,
                'OMIP2016-SIN' => 4,
                'OMIP2016-SLP' => 4,
                'OMIP2016-SON' => 4,
                'OMIP2016-TAB' => 4,
                'OMIP2016-TAM' => 4,
                'OMIP2016-TLA' => 4,
                'OMIP2016-VER' => 4,
                'OMIP2016-YUC' => 4,
                'OMIP2016-ZAC' => 4,
                'OMIP2016-INV' => 4,
            );
        } elseif ($r['contest_type'] == 'OMISN') {
            if ($r['current_user']->getUsername() != 'andreasantillana'
                && !$is_system_admin
            ) {
                throw new ForbiddenAccessException();
            }

            $keys = array(
                'OMIS2016-AGU' => 4,
                'OMIS2016-BCN' => 4,
                'OMIS2016-BCS' => 4,
                'OMIS2016-CAM' => 4,
                'OMIS2016-CHH' => 4,
                'OMIS2016-CHP' => 4,
                'OMIS2016-CMX' => 4,
                'OMIS2016-COA' => 4,
                'OMIS2016-COL' => 4,
                'OMIS2016-DUR' => 4,
                'OMIS2016-GRO' => 4,
                'OMIS2016-GUA' => 4,
                'OMIS2016-HID' => 4,
                'OMIS2016-JAL' => 4,
                'OMIS2016-MEX' => 4,
                'OMIS2016-MIC' => 4,
                'OMIS2016-MOR' => 4,
                'OMIS2016-NAY' => 4,
                'OMIS2016-NLE' => 4,
                'OMIS2016-OAX' => 4,
                'OMIS2016-PUE' => 4,
                'OMIS2016-QTO' => 4,
                'OMIS2016-ROO' => 4,
                'OMIS2016-SIN' => 4,
                'OMIS2016-SLP' => 4,
                'OMIS2016-SON' => 4,
                'OMIS2016-TAB' => 4,
                'OMIS2016-TAM' => 4,
                'OMIS2016-TLA' => 4,
                'OMIS2016-VER' => 4,
                'OMIS2016-YUC' => 4,
                'OMIS2016-ZAC' => 4,
                'OMIS2016-INV' => 4,
            );
        } elseif ($r['contest_type'] == 'ORIG') {
            if ($r['current_user']->getUsername() != 'kuko.coder'
                && !$is_system_admin
            ) {
                throw new ForbiddenAccessException();
            }

            $keys = array (
                'ORIG1516-CEL' => 38,
                'ORIG1516-DHI' => 15,
                'ORIG1516-GTO' => 14,
                'ORIG1516-IRA' => 37,
                'ORIG1516-PEN' => 22,
                'ORIG1516-LEO' => 43,
                'ORIG1516-SLP' => 14,
                'ORIG1516-SLV' => 14,
                'ORIG1516-URI' => 17,
                'ORIG1516-VDS' => 15,
            );
        } elseif ($r['contest_type'] == 'OMIAGS') {
            if ($r['current_user']->getUsername() != 'andreasantillana'
                && !$is_system_admin
            ) {
                throw new ForbiddenAccessException();
            }

            $keys = array (
                'OMIAGS' => 35
            );
        } elseif ($r['contest_type'] == 'OSI') {
            if ($r['current_user']->getUsername() != 'cope_quintana'
                && !$is_system_admin
            ) {
                throw new ForbiddenAccessException();
            }

            $keys = array (
                'OSI16' => 120
            );
        } elseif ($r['contest_type'] == 'UNAMFC') {
            if ($r['current_user']->getUsername() != 'manuelalcantara52'
                && $r['current_user']->getUsername() != 'manuel52'
                && !$is_system_admin
            ) {
                throw new ForbiddenAccessException();
            }
            $keys = array (
                'UNAMFC15' => 30
            );
        } elseif ($r['contest_type'] == 'OVI') {
            if ($r['current_user']->getUsername() != 'covi.academico'
                && !$is_system_admin
            ) {
                throw new ForbiddenAccessException();
            }
            $keys = array (
                'OVI15' => 200
            );
        } elseif ($r['contest_type'] == 'PROFEST') {
            if ($r['current_user']->getUsername() != 'Diego_Briaares'
                && !$is_system_admin
            ) {
                throw new ForbiddenAccessException();
            }
            $keys = array (
                'PROFEST-2016' => 18
            );
        } elseif ($r['contest_type'] == 'CCUPITSUR') {
            if ($r['current_user']->getUsername() != 'licgerman-yahoo'
                && !$is_system_admin
            ) {
                throw new ForbiddenAccessException();
            }
            // Arreglo de concurso
            $keys = array(
                'CCUPITSUR-16' => 50,
            );
        } else {
            throw new InvalidParameterException(
                'parameterNotInExpectedSet',
                'contest_type',
                array(
                    'bad_elements' => $r['contest_type'],
                    'expected_set' => 'OMI, OMIAGS, ORIG, OSI, OVI, PROFEST, CCUPITSUR',
                )
            );
        }

        self::$permissionKey = $r['permission_key'] = self::randomString(32);

        foreach ($keys as $k => $n) {
            $digits = floor(log10($n) + 1);
            for ($i = 1; $i <= $n; $i++) {
                $username = $k . '-' . str_pad($i, $digits, '0', STR_PAD_LEFT);
                $password = self::randomString(8);

                if (self::omiPrepareUser($r, $username, $password)) {
                    $response[$username] = $password;
                }

                // Add user to contest if needed
                if (!is_null($r['contest_alias'])) {
                    $addUserRequest = new Request();
                    $addUserRequest['auth_token'] = $r['auth_token'];
                    $addUserRequest['usernameOrEmail'] = $username;
                    $addUserRequest['contest_alias'] = $r['contest_alias'];
                    ContestController::apiAddUser($addUserRequest);
                }
            }
        }

        return $response;
    }

    /**
     * Get list of contests where the user has admin priviledges
     *
     * @param Request $r
     * @return string
     * @throws InvalidDatabaseOperationException
     */
    public static function apiContests(Request $r) {
        self::authenticateRequest($r);

        $response = array();
        $response['contests'] = array();

        try {
            $contest_director_key = new Contests(array(
                        'director_id' => $r['current_user_id']
                    ));
            $contests_director = ContestsDAO::search($contest_director_key);

            foreach ($contests_director as $contest) {
                $response['contests'][] = $contest->asArray();
            }

            $contest_admin_key = new UserRoles(array(
                        'user_id' => $r['current_user_id'],
                        'role_id' => CONTEST_ADMIN_ROLE,
                    ));
            $contests_admin = UserRolesDAO::search($contest_admin_key);

            foreach ($contests_admin as $contest_key) {
                $contest = ContestsDAO::getByPK($contest_key->getContestId());

                if (is_null($contest)) {
                    self::$log->error("UserRoles has a invalid contest: {$contest->getContestId()}");
                    continue;
                }

                $response['contests'][] = $contest->asArray();
            }

            usort($response['contests'], function ($a, $b) {
                        return ($a['contest_id'] > $b['contest_id']) ? -1 : 1;
            });
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        $response['status'] = 'ok';
        return $response;
    }

    /**
     * Get list of my editable problems
     *
     * @param Request $r
     * @return string
     * @throws InvalidDatabaseOperationException
     */
    public static function apiProblems(Request $r) {
        self::authenticateRequest($r);

        $response = array();
        $response['problems'] = array();

        try {
            $problems_key = new Problems(array(
                        'author_id' => $r['current_user_id']
                    ));

            $problems = ProblemsDAO::search($problems_key);

            foreach ($problems as $problem) {
                $response['problems'][] = $problem->asArray();
            }

            usort($response['problems'], function ($a, $b) {
                        return ($a['problem_id'] > $b['problem_id']) ? -1 : 1;
            });
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        $response['status'] = 'ok';
        return $response;
    }

    /**
     * Returns the prefered language as a string (en,es,fra) of the user given
     * If no user is give, language is retrived from the browser.
     *
     * @param Users $user
     * @return String
     */
    public static function getPreferredLanguage(Request $r = null) {
        // for quick debugging
        if (isset($_GET['lang'])) {
            return UserController::convertToSupportedLanguage($_GET['lang']);
        }

        try {
            $user = self::resolveTargetUser($r);
            if (!is_null($user) && !is_null($user->getLanguageId())) {
                $result = LanguagesDAO::getByPK($user->getLanguageId());
                if (is_null($result)) {
                    self::$log->warn('Invalid language id for user');
                } else {
                    return UserController::convertToSupportedLanguage($result->getName());
                }
            }
        } catch (NotFoundException $ex) {
            self::$log->debug($ex);
        } catch (InvalidParameterException $ex) {
            self::$log->debug($ex);
        }

        $langs = array();

        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            // break up string into pieces (languages and q factors)
            preg_match_all('/([a-z]{1,8}(-[a-z]{1,8})?)\s*(;\s*q\s*=\s*(1|0\.[0-9]+))?/i', $_SERVER['HTTP_ACCEPT_LANGUAGE'], $lang_parse);

            if (count($lang_parse[1])) {
                // create a list like "en" => 0.8
                $langs = array_combine($lang_parse[1], $lang_parse[4]);

                // set default to 1 for any without q factor
                foreach ($langs as $lang => $val) {
                    if ($val === '') {
                        $langs[$lang] = 1;
                    }
                }

                // sort list based on value
                arsort($langs, SORT_NUMERIC);
            }
        }

        foreach ($langs as $langCode => $langWeight) {
            switch (substr($langCode, 0, 2)) {
                case 'en':
                    return 'en';

                case 'es':
                    return 'es';

                case 'pt':
                    return 'pt';
            }
        }

        // Fallback to spanish.
        return 'es';
    }

    private static function convertToSupportedLanguage($lang) {
        switch ($lang) {
            case 'en':
            case 'en-us':
                return 'en';

            case 'es':
            case 'es-mx':
                return 'es';

            case 'pt':
            case 'pt-pt':
            case 'pt-br':
                return 'pt';

            case 'pseudo':
                return 'pseudo';
        }

        // Fallback to spanish.
        return 'es';
    }

    /**
     * Returns the profile of the user given
     *
     * @param Users $user
     * @return array
     * @throws InvalidDatabaseOperationException
     */
    private static function getProfileImpl(Users $user) {
        $response = array();
        $response['userinfo'] = array();
        $response['problems'] = array();

        $response['userinfo']['username'] = $user->getUsername();
        $response['userinfo']['name'] = $user->getName();
        $response['userinfo']['solved'] = $user->getSolved();
        $response['userinfo']['submissions'] = $user->getSubmissions();
        $response['userinfo']['birth_date'] = is_null($user->getBirthDate()) ? null : strtotime($user->getBirthDate());
        $response['userinfo']['graduation_date'] = is_null($user->getGraduationDate()) ? null : strtotime($user->getGraduationDate());
        $response['userinfo']['scholar_degree'] = $user->getScholarDegree();

        if (!is_null($user->getLanguageId())) {
            $query = LanguagesDAO::getByPK($user->getLanguageId());
            if (!is_null($query)) {
                $response['userinfo']['locale'] =
                    UserController::convertToSupportedLanguage($query->getName());
            }
        }

        try {
            $response['userinfo']['email'] = EmailsDAO::getByPK($user->getMainEmailId())->getEmail();

            $country = CountriesDAO::getByPK($user->getCountryId());
            $response['userinfo']['country'] = is_null($country) ? null : $country->getName();
            $response['userinfo']['country_id'] = $user->getCountryId();

            $state = StatesDAO::getByPK($user->getStateId());
            $response['userinfo']['state'] = is_null($state) ? null : $state->getName();
            $response['userinfo']['state_id'] = $user->getStateId();

            $school = SchoolsDAO::getByPK($user->getSchoolId());
            $response['userinfo']['school_id'] = $user->getSchoolId();
            $response['userinfo']['school'] = is_null($school) ? null : $school->getName();
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        $response['userinfo']['gravatar_92'] = 'https://secure.gravatar.com/avatar/' . md5($response['userinfo']['email']) . '?s=92';

        return $response;
    }

    /**
     * Get user profile from cache
     * Requires $r["user"] to be an actual User
     *
     * @param Request $r
     * @param array $response
     * @param Request $r
     * @return type
     */
    public static function getProfile(Request $r) {
        if (is_null($r['user'])) {
            throw new InvalidParameterException('parameterNotFound', 'User');
        }

        $response = array();

        Cache::getFromCacheOrSet(
            Cache::USER_PROFILE,
            $r['user']->getUsername(),
            $r,
            function (Request $r) {
                    return UserController::getProfileImpl($r['user']);
            },
            $response
        );

        if (is_null($r['omit_rank']) || !$r['omit_rank']) {
            $response['userinfo']['rankinfo'] = self::getRankByProblemsSolved($r);
        } else {
            $response['userinfo']['rankinfo'] = array();
        }

        // Do not leak plain emails in case the request is for a profile other than
        // the logged user's one
        if ($r['user']->getUserId() !== $r['current_user_id']) {
            unset($response['userinfo']['email']);
        }

        return $response;
    }

    /**
     * Get general user info
     *
     * @param Request $r
     * @return response array with user info
     * @throws InvalidDatabaseOperationException
     */
    public static function apiProfile(Request $r) {
        self::authenticateOrAllowUnauthenticatedRequest($r);

        $r['user'] = self::resolveTargetUser($r);

        $response = self::getProfile($r);

        $response['status'] = 'ok';
        return $response;
    }

    /**
     * Get coder of the month by trying to find it in the table using the first
     * day of the current month. If there's no coder of the month for the given
     * date, calculate it and save it.
     *
     * @param Request $r
     * @return array
     * @throws InvalidDatabaseOperationException
     */
    public static function apiCoderOfTheMonth(Request $r) {
        // Get first day of the current month
        $firstDay = date('Y-m-01');

        try {
            $coderOfTheMonth = null;

            $codersOfTheMonth = CoderOfTheMonthDAO::search(new CoderOfTheMonth(array('time' => $firstDay)));
            if (count($codersOfTheMonth) > 0) {
                $coderOfTheMonth = $codersOfTheMonth[0];
            }

            if (is_null($coderOfTheMonth)) {
                // Generate the coder
                $retArray = CoderOfTheMonthDAO::calculateCoderOfTheMonth($firstDay);
                if ($retArray == null) {
                    throw new InvalidParameterException('parameterInvalid', 'date');
                }

                $user = $retArray['user'];

                // Save it
                $c = new CoderOfTheMonth(array(
                    'user_id' => $user->user_id,
                    'time' => $firstDay,

                ));
                CoderOfTheMonthDAO::save($c);
            } else {
                // Grab the user info
                $user = UsersDAO::getByPK($coderOfTheMonth->user_id);
            }
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        // Get the profile of the coder of the month
        $response = self::getProfileImpl($user);

        $response['status'] = 'ok';
        return $response;
    }

    /**
     * Returns the list of coders of the month
     *
     * @param Request $r
     */
    public static function apiCoderOfTheMonthList(Request $r) {
        $response = array();
        $response['coders'] = array();
        try {
            $coders = CoderOfTheMonthDAO::getAll(null, null, 'time', 'DESC');

            foreach ($coders as $c) {
                $user = UsersDAO::getByPK($c->user_id);
                $email = EmailsDAO::getByPK($user->getMainEmailId());
                $response['coders'][] = array(
                    'username' => $user->getUsername(),
                    'gravatar_32' => 'https://secure.gravatar.com/avatar/' . md5($email->getEmail()) . '?s=32',
                    'date' => $c->getTime()
                );
            }
        } catch (Exception $ex) {
            throw new InvalidDatabaseOperationException($e);
        }

        $response['status'] = 'ok';
        return $response;
    }

    /**
     * Get Contests which a certain user has participated in
     *
     * @param Request $r
     * @return Contests array
     * @throws InvalidDatabaseOperationException
     */
    public static function apiContestStats(Request $r) {
        self::authenticateOrAllowUnauthenticatedRequest($r);

        $response = array();
        $response['contests'] = array();

        $user = self::resolveTargetUser($r);

        // Get contests where user had at least 1 run
        try {
            $contestsParticipated = ContestsDAO::getContestsParticipated($user->getUserId());
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        $contests = array();
        foreach ($contestsParticipated as $contest) {
            // Get user ranking
            $scoreboardR = new Request(array(
                'auth_token' => $r['auth_token'],
                'contest_alias' => $contest->getAlias(),
                'token' => $contest->getScoreboardUrlAdmin()
            ));
            $scoreboardResponse = ContestController::apiScoreboard($scoreboardR);

            // Grab the place of the current user in the given contest
            $contests[$contest->getAlias()]['place']  = null;
            foreach ($scoreboardResponse['ranking'] as $userData) {
                if ($userData['username'] == $user->getUsername()) {
                    $contests[$contest->getAlias()]['place'] = $userData['place'];
                    break;
                }
            }

            $contest->toUnixTime();
            $contests[$contest->getAlias()]['data'] = $contest->asArray();
        }

        $response['contests'] = $contests;
        $response['status'] = 'ok';
        return $response;
    }

    /**
     * Get Problems solved by user
     *
     * @param Request $r
     * @return Problems array
     * @throws InvalidDatabaseOperationException
     */
    public static function apiProblemsSolved(Request $r) {
        self::authenticateOrAllowUnauthenticatedRequest($r);

        $response = array();
        $response['problems'] = array();

        $user = self::resolveTargetUser($r);

        try {
            $db_results = ProblemsDAO::getProblemsSolved($user->getUserId());
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        if (!is_null($db_results)) {
            $relevant_columns = array('title', 'alias', 'submissions', 'accepted');
            foreach ($db_results as $problem) {
                if ($problem->getPublic() == 1) {
                    array_push($response['problems'], $problem->asFilteredArray($relevant_columns));
                }
            }
        }

        $response['status'] = 'ok';
        return $response;
    }

    /**
     * Gets a list of users. This returns an array instead of an object since
     * it is used by typeahead.
     *
     * @param Request $r
     */
    public static function apiList(Request $r) {
        self::authenticateRequest($r);

        $param = '';
        if (!is_null($r['term'])) {
            $param = 'term';
        } elseif (!is_null($r['query'])) {
            $param = 'query';
        } else {
            throw new InvalidParameterException('parameterEmpty', 'query');
        }

        try {
            $users = UsersDAO::FindByUsernameOrName($r[$param]);
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        $response = array();
        foreach ($users as $user) {
            $entry = array('label' => $user->getUsername(), 'value' => $user->getUsername());
            array_push($response, $entry);
        }

        return $response;
    }

    /**
     * Get stats
     *
     * @param Request $r
     */
    public static function apiStats(Request $r) {
        self::authenticateOrAllowUnauthenticatedRequest($r);
        $user = self::resolveTargetUser($r);

        try {
            $totalRunsCount = RunsDAO::CountTotalRunsOfUser($user->getUserId());

            // List of verdicts
            $verdict_counts = array();

            foreach (self::$verdicts as $verdict) {
                $verdict_counts[$verdict] = RunsDAO::CountTotalRunsOfUserByVerdict($user->getUserId(), $verdict);
            }
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        return array(
            'verdict_counts' => $verdict_counts,
            'total_runs' => $totalRunsCount,
            'status' => 'ok'
        );
    }

    /**
     * Update basic user profile info when logged with fb/gool
     *
     * @param Request $r
     * @return array
     * @throws InvalidDatabaseOperationException
     * @throws InvalidParameterException
     */
    public static function apiUpdateBasicInfo(Request $r) {
        self::authenticateRequest($r);

        //Buscar que el nuevo username no este ocupado si es que selecciono uno nuevo
        if ($r['username'] != $r['current_user']->getUsername()) {
            $testu = UsersDAO::FindByUsername($r['username']);

            if (!is_null($testu)) {
                throw new InvalidParameterException('parameterUsernameInUse', 'username');
            }

            Validators::isValidUsername($r['username'], 'username');
            $r['current_user']->setUsername($r['username']);
        }

        SecurityTools::testStrongPassword($r['password']);
        $hashedPassword = SecurityTools::hashString($r['password']);
        $r['current_user']->setPassword($hashedPassword);

        UsersDAO::save($r['current_user']);

        return array('status' => 'ok');
    }

    /**
     * Update user profile
     *
     * @param Request $r
     * @return array
     * @throws InvalidDatabaseOperationException
     * @throws InvalidParameterException
     */
    public static function apiUpdate(Request $r) {
        self::authenticateRequest($r);

        Validators::isStringNonEmpty($r['name'], 'name', false);
        Validators::isStringNonEmpty($r['country_id'], 'country_id', false);

        if (!is_null($r['country_id'])) {
            try {
                $r['country'] = CountriesDAO::getByPK($r['country_id']);
            } catch (Exception $e) {
                throw new InvalidDatabaseOperationException($e);
            }
        }

        if ($r['state_id'] === 'null') {
            $r['state_id'] = null;
        }

        Validators::isNumber($r['state_id'], 'state_id', false);

        if (!is_null($r['state_id'])) {
            try {
                $r['state'] = StatesDAO::getByPK($r['state_id']);
            } catch (Exception $e) {
                throw new InvalidDatabaseOperationException($e);
            }
        }

        if (!is_null($r['school_id'])) {
            if (is_numeric($r['school_id'])) {
                try {
                    $r['school'] = SchoolsDAO::getByPK($r['school_id']);
                } catch (Exception $e) {
                    throw new InvalidDatabaseOperationException($e);
                }

                if (is_null($r['school'])) {
                    throw new InvalidParameterException('parameterInvalid', 'school');
                }
            } elseif (empty($r['school_name'])) {
                $r['school_id'] = null;
            } else {
                try {
                    $schoolR = new Request(array('name' => $r['school_name'], 'state_id' => $r['state_id'], 'auth_token' => $r['auth_token']));
                    $response = SchoolController::apiCreate($schoolR);
                    $r['school_id'] = $response['school_id'];
                } catch (Exception $e) {
                    throw new InvalidDatabaseOperationException($e);
                }
            }
        }

        Validators::isStringNonEmpty($r['scholar_degree'], 'scholar_degree', false);

        if (!is_null($r['graduation_date'])) {
            if (is_numeric($r['graduation_date'])) {
                $r['graduation_date'] = (int)$r['graduation_date'];
            } else {
                Validators::isDate($r['graduation_date'], 'graduation_date', false);
                $r['graduation_date'] = strtotime($r['graduation_date']);
            }
        }
        if (!is_null($r['birth_date'])) {
            if (is_numeric($r['birth_date'])) {
                $r['birth_date'] = (int)$r['birth_date'];
            } else {
                Validators::isDate($r['birth_date'], 'birth_date', false);
                $r['birth_date'] = strtotime($r['birth_date']);
            }
        }

        if (!is_null($r['locale'])) {
            // find language in Language
            $query = LanguagesDAO::search(new Languages(array( 'name' => $r['locale'])));
            if (sizeof($query) == 1) {
                $r['current_user']->setLanguageId($query[0]->getLanguageId());
            }
        }

        $valueProperties = array(
            'name',
            'country_id',
            'state_id',
            'scholar_degree',
            'school_id',
            'graduation_date' => array('transform' => function ($value) {
                return gmdate('Y-m-d', $value);
            }),
            'birth_date' => array('transform' => function ($value) {
                return gmdate('Y-m-d', $value);
            }),
        );

        self::updateValueProperties($r, $r['current_user'], $valueProperties);

        try {
            UsersDAO::save($r['current_user']);
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        // Expire profile cache

        Cache::deleteFromCache(Cache::USER_PROFILE, $r['current_user']->getUsername());
        $sessionController = new SessionController();
        $sessionController->InvalidateCache();

        return array('status' => 'ok');
    }

    /**
     * If no username provided: Gets the top N users who have solved more problems
     * If username provided: Gets rank for username provided
     *
     * @param Request $r
     * @return string
     * @throws InvalidDatabaseOperationException
     */

    public static function apiRankByProblemsSolved(Request $r) {
        Validators::isNumber($r['offset'], 'offset', false);
        Validators::isNumber($r['rowcount'], 'rowcount', false);

        $r['user'] = null;
        if (!is_null($r['username'])) {
            Validators::isStringNonEmpty($r['username'], 'username');
            try {
                $r['user'] = UsersDAO::FindByUsername($r['username']);
                if (is_null($r['user'])) {
                    throw new NotFoundException('userNotExist');
                }
            } catch (ApiException $e) {
                throw $e;
            } catch (Exception $e) {
                throw new InvalidDatabaseOperationException($e);
            }
        }

        // Defaults for offset and rowcount
        if (null == $r['offset']) {
            $r['offset'] = 1;
        }
        if (null == $r['rowcount']) {
            $r['rowcount'] = 100;
        }

        return self::getRankByProblemsSolved($r);
    }

    /**
     * Get rank by problems solved logic. It has its own func so
     * it can be accesed internally without authentication
     *
     * @param Request $r
     * @throws InvalidDatabaseOperationException
     */
    private static function getRankByProblemsSolved(Request $r) {
        if (is_null($r['user'])) {
            $rankCacheName =  $r['offset'] . '-' . $r['rowcount'];

            $cacheUsed = Cache::getFromCacheOrSet(Cache::PROBLEMS_SOLVED_RANK, $rankCacheName, $r, function (Request $r) {
                $response = array();
                $response['rank'] = array();
                try {
                    $userRankEntries = UserRankDAO::getAll($r['offset'], $r['rowcount'], 'Rank', 'ASC');
                } catch (Exception $e) {
                    throw new InvalidDatabaseOperationException($e);
                }

                if (!is_null($userRankEntries)) {
                    foreach ($userRankEntries as $userRank) {
                        array_push($response['rank'], array(
                            'username' => $userRank->username,
                            'name' => $userRank->name,
                            'problems_solved' => $userRank->problems_solved_count,
                            'rank' => $userRank->rank,
                            'score' => $userRank->score,
                            'country_id' => $userRank->country_id));
                    }
                }
                return $response;
            }, $response, APC_USER_CACHE_USER_RANK_TIMEOUT);

            // If cache was set, we need to maintain a list of different ranks in the cache
            // (A different rankCacheName means different offset and rowcount params
            if ($cacheUsed === false) {
                self::setProblemsSolvedRankCacheList($rankCacheName);
            }
        } else {
            $response = array();

            try {
                $userRank = UserRankDAO::getByPK($r['user']->user_id);
            } catch (Exception $e) {
                throw new InvalidDatabaseOperationException($e);
            }

            if (!is_null($userRank)) {
                $response['rank'] = $userRank->rank;
                $response['name'] = $r['user']->name;
                $response['problems_solved'] = $userRank->problems_solved_count;
            } else {
                $response['rank'] = 0;
                $response['name'] = $r['user']->name;
                $response['problems_solved'] = 0;
            }
        }

        $response['status'] = 'ok';
        return $response;
    }

    /**
     * Adds the rank name to a list of stored ranks so we know we ranks to delete
     * after
     *
     * @param string $rankCacheName
     */
    private static function setProblemsSolvedRankCacheList($rankCacheName) {
        // Save the instance of the rankName in a key/value array, so we know all ranks to
        // expire
        $rankCacheList = new Cache(Cache::PROBLEMS_SOLVED_RANK_LIST, '');
        $ranksList = $rankCacheList->get();

        if (is_null($ranksList)) {
            // Simulating a set
            $ranksList = array($rankCacheName => 1);
        } else {
            $ranksList[$rankCacheName] = 1;
        }

        $rankCacheList->set($ranksList, 0);
    }

    /**
     * Expires the known ranks
     * @TODO: This should be called only in the grader->frontend callback and only IFF
     * verdict = AC (and not test run)
     */
    public static function deleteProblemsSolvedRankCacheList() {
        $rankCacheList = new Cache(Cache::PROBLEMS_SOLVED_RANK_LIST, '');
        $ranksList = $rankCacheList->get();

        if (!is_null($ranksList)) {
            $rankCacheList->delete();

            foreach ($ranksList as $key => $value) {
                Cache::deleteFromCache(Cache::PROBLEMS_SOLVED_RANK, $key);
            }
        }
    }

    /**
     * Forza un refresh de la tabla User_Rank. SysAdmin only.
     *
     * @param Request $r
     * @return array
     * @throws UnauthorizedException
     */
    public static function apiRefreshUserRank(Request $r) {
        self::authenticateRequest($r);

        if (!Authorization::IsSystemAdmin($r['current_user_id'])) {
            throw new UnauthorizedException();
        }

        // Actualizar tabla User_Rank
        try {
            UserRankDAO::refreshUserRank();
        } catch (Exception $ex) {
            throw new InvalidDatabaseOperationException($ex);
        }

        // Borrar todos los ranks cacheados
        self::deleteProblemsSolvedRankCacheList();

        return array('status' => 'ok');
    }

    /**
     * Updates the main email of the current user
     *
     * @param Request $r
     */
    public static function apiUpdateMainEmail(Request $r) {
        self::authenticateRequest($r);

        Validators::isEmail($r['email'], 'email');

        try {
            // Update email
            $email = EmailsDAO::getByPK($r['current_user']->getMainEmailId());
            $email->setEmail($r['email']);
            EmailsDAO::save($email);

            // Add verification_id if not there
            if ($r['current_user']->getVerified() == '0') {
                self::$log->info('User not verified.');

                if ($r['current_user']->getVerificationId() == null) {
                    self::$log->info('User does not have verification id. Generating.');

                    try {
                        $r['current_user']->setVerificationId(self::randomString(50));
                        UsersDAO::save($r['current_user']);
                    } catch (Exception $e) {
                        // best effort, eat exception
                    }
                }
            }
        } catch (Exception $e) {
            // If duplicate in DB
            if (strpos($e->getMessage(), '1062') !== false) {
                throw new DuplicatedEntryInDatabaseException('mailInUse');
            } else {
                throw new InvalidDatabaseOperationException($e);
            }
        }

        // Delete profile cache
        Cache::deleteFromCache(Cache::USER_PROFILE, $r['current_user']->getUsername());

        // Send verification email
        $r['user'] = $r['current_user'];
        self::sendVerificationEmail($r);

        return array('status' => 'ok');
    }
}
