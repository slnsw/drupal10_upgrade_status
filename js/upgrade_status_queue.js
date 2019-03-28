(function ($, Drupal) {
  Drupal.behaviors.batch = {
    attach: function attach(context, settings) {
      var batch = settings.batch;
      var $progress = $('[data-drupal-progress]').once('batch');
      var progressBar = void 0;

      function updateCallback(progress, status, pb) {
        if (progress === '100') {
          pb.stopMonitoring();
          window.location = batch.uri;
        }
      }

      function errorCallback(pb) {
        $progress.prepend($('<p class="error"></p>').json(batch.errorMessage));
        $('#wait').hide();
      }

      if ($progress.length) {
        progressBar = new Drupal.ProgressBar('queueprogress', updateCallback, 'POST', errorCallback);
        progressBar.setProgress(-1, batch.initMessage);
        progressBar.startMonitoring(batch.uri, 10);

        $progress.empty();

        $progress.append(progressBar.element);
      }
    }
  };
})(jQuery, Drupal);
