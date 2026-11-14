/**
 * @file
 * The group tour moves to each tip without animation for people who ask for
 * less motion.
 *
 * Core's tour hands every tip scrollIntoView({behavior: 'smooth'}), which a
 * stylesheet cannot override. This library loads before core's tour script
 * (see localnodes_platform_library_info_alter()), so this behavior runs before
 * the tour's own and changes the setting before any tip is built.
 */
((Drupal) => {
  Drupal.behaviors.localnodesTourReducedMotion = {
    attach(context, settings) {
      const scrollTo = settings.tourShepherdConfig?.defaultStepOptions?.scrollTo;
      if (
        scrollTo &&
        window.matchMedia &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
      ) {
        scrollTo.behavior = 'auto';
      }
    },
  };
})(Drupal);
