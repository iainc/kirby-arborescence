<template>
  <k-dialog
    :cancel-button="false"
    :submit-button="false"
    :visible="true"
    class="k-arborescence-search-dialog"
    role="search"
    size="medium"
    @cancel="close"
  >
    <arborescence
      ref="arbo"
      :autofocus="true"
      :close-on-select="true"
      standalone-root-page="site"
      :standalone-show-parent="true"
    />
  </k-dialog>
</template>

<script>
import Arborescence from "./Arborescence.vue";

export default {
  components: {
    Arborescence,
  },
  mounted() {
    this.$nextTick(() => {
      this.$refs.arbo?.focusSearch();
    });
  },
  methods: {
    close() {
      this.$panel.dialog.close();
    },
  },
};
</script>

<style>
.k-arborescence-search-dialog {
  --arborescence-dialog-inset: var(--spacing-2);
  --dialog-padding: 0;
  --dialog-width: 32rem;
  --dialog-rounded: var(--rounded-xl);
  block-size: min(36rem, calc(100dvh - 2 * var(--dialog-margin)));
  overflow: hidden;
}

.k-arborescence-search-dialog .k-dialog-body {
  flex: 1 1 auto;
  min-block-size: 0;
  overflow: hidden;
}

.k-arborescence-search-dialog .k-arborescence-section {
  display: flex;
  flex-direction: column;
  block-size: 100%;
  min-block-size: 0;
}

.k-arborescence-search-dialog .k-arborescence-search {
  flex: 0 0 auto;
  margin-bottom: 0;
  position: relative;
  z-index: 2;
  padding:
    var(--arborescence-dialog-inset)
    var(--arborescence-dialog-inset)
    0;
}

.k-arborescence-search-dialog .k-arborescence-section > .k-tree,
.k-arborescence-search-dialog .k-arborescence-section > .k-empty {
  flex: 1 1 auto;
  min-block-size: 0;
  overflow: auto;
  overscroll-behavior: contain;
  padding:
    var(--arborescence-dialog-inset)
    var(--arborescence-dialog-inset)
    var(--arborescence-dialog-inset);
}
</style>
