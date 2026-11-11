/**
 * @file
 * The "Report Sent" page (23-05, UI-SPEC section 10 "Success"): focus moves
 * to the page's h1, so a screen reader starts at the confirmation.
 */
(function (Drupal, once) {
  Drupal.behaviors.localnodesImportReportSent = {
    attach: function (context) {
      once('localnodes-import-report-sent', 'h1', context).slice(0, 1).forEach(function (heading) {
        heading.setAttribute('tabindex', '-1');
        heading.focus();
      });
    },
  };
})(Drupal, once);
