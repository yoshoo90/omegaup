<template>
  <div class="panel badge-details-panel">
    <h1 class="text-center">{{ name }}</h1>
    <figure class="badge-info-grid">
      <img
        class="badge-icon"
        v-bind:class="{ 'badge-gray': !this.badge.assignation_time }"
        v-bind:src="iconUrl"
      />
      <figcaption class="badge-description">
        {{ description }}
      </figcaption>
    </figure>
    <div class="badge-details-grid">
      <div class="badge-detail">
        <div class="badge-detail-data">
          {{ ownersNumber }}
        </div>
        <div class="badge-detail-text">
          <span class="badge-detail-text-icon">👥</span>
          {{ T['badgeOwnersMessage'] }}
        </div>
      </div>
      <div class="badge-detail">
        <div class="badge-detail-data">
          {{ firstAssignationDate }}
        </div>
        <div class="badge-detail-text">
          <span class="badge-detail-text-icon">📅</span>
          {{ T['badgeFirstAssignationMessage'] }}
        </div>
      </div>
      <div class="badge-detail">
        <div class="badge-detail-data">
          {{ assignationDate }}
        </div>
        <div class="badge-detail-text" v-html="ownedMessage"></div>
      </div>
    </div>
  </div>
</template>

<style>
.badge-details-panel {
  padding: 15px;
}
.badge-info-grid,
.badge-details-grid {
  display: grid;
  justify-content: center;
  justify-items: center;
  align-items: center;
  text-align: center;
  row-gap: 40px;
  column-gap: 20px;
  padding: 30px 20px;
}
.badge-info-grid {
  grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
}
.badge-details-grid {
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
}
.badge-icon {
  max-width: 300px;
}
.badge-description {
  font-size: 20px;
}
.badge-detail {
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
}
.badge-detail-data {
  font-size: 45px;
  font-weight: bold;
}
.badge-detail-text {
  font-size: 18px;
}
.badge-detail-text-icon {
  font-size: 30px;
}
.badge-gray {
  filter: grayscale(100%);
}
</style>

<script lang="ts">
import { Vue, Component, Prop } from 'vue-property-decorator';
import { types } from '../../api_types';
import T from '../../lang';
import * as time from '../../time';

@Component
export default class BadgeDetails extends Vue {
  @Prop() badge!: types.Badge;

  T = T;

  get name(): string {
    return T[`badge_${this.badge.badge_alias}_name`];
  }

  get description(): string {
    return T[`badge_${this.badge.badge_alias}_description`];
  }

  get iconUrl(): string {
    return `/media/dist/badges/${this.badge.badge_alias}.svg`;
  }

  get ownedMessage(): string {
    return !!this.badge.assignation_time
      ? `<span class="badge-detail-text-icon">😁</span> ${T['badgeAssignationTimeMessage']}`
      : `<span class="badge-detail-text-icon">😞</span> ${T['badgeNotAssignedMessage']}`;
  }

  get firstAssignationDate(): string {
    return this.badge.first_assignation
      ? time.formatDate(this.badge.first_assignation)
      : '';
  }

  get assignationDate(): string {
    return !!this.badge.assignation_time
      ? time.formatDate(this.badge.assignation_time)
      : '';
  }

  get ownersNumber(): string {
    return `${this.badge.owners_count}/${this.badge.total_users}`;
  }
}
</script>
