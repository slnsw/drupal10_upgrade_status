/**
 * Progress bar code based heavily on progress.js.
 *
 * Ensure to get the AJAX result in the change callback and customized classes.
 */
(function ($, Drupal) {
  Drupal.theme.statusProgressBar = function (id) {
    return '<div id="' + id + '" class="progress" aria-live="polite">' + '<div class="progress__label">' + Drupal.t('Scanning projects...') + '</div>' + '<div class="progress__track"><div class="progress__bar"></div></div>' + '<div class="progress__percentage"></div>' + '<div class="progress__description">&nbsp;</div>' + '</div>';
  };

  Drupal.UpgradeStatusProgressBar = function (id, updateCallback, method, errorCallback) {
    this.id = id;
    this.method = method || 'GET';
    this.updateCallback = updateCallback;
    this.errorCallback = errorCallback;

    this.element = $(Drupal.theme('statusProgressBar', id));
  };

  $.extend(Drupal.UpgradeStatusProgressBar.prototype, {
    setProgress: function setProgress(progress) {
      if (progress.percentage >= 0 && progress.percentage <= 100) {
        $(this.element).find('div.progress__bar').css('width', progress.percentage + '%');
        $(this.element).find('div.progress__percentage').html(progress.percentage + '%');
      }
      $('div.progress__description', this.element).html(progress.message);
      $('div.progress__label', this.element).html(progress.label);
      if (this.updateCallback) {
        this.updateCallback(progress, this);
      }
    },
    startMonitoring: function startMonitoring(uri, delay) {
      this.delay = delay;
      this.uri = uri;
      this.sendPing();
    },
    stopMonitoring: function stopMonitoring() {
      clearTimeout(this.timer);

      this.uri = null;
    },
    sendPing: function sendPing() {
      if (this.timer) {
        clearTimeout(this.timer);
      }
      if (this.uri) {
        var pb = this;

        var uri = this.uri;
        if (uri.indexOf('?') === -1) {
          uri += '?';
        } else {
          uri += '&';
        }
        uri += '_format=json';
        $.ajax({
          type: this.method,
          url: uri,
          data: '',
          dataType: 'json',
          success: function success(progress) {
            if (progress.status === 0) {
              pb.displayError(progress.data);
              return;
            }

            pb.setProgress(progress);

            pb.timer = setTimeout(function () {
              pb.sendPing();
            }, pb.delay);
          },
          error: function error(xmlhttp) {
            var e = new Drupal.AjaxError(xmlhttp, pb.uri);
            pb.displayError('<pre>' + e.message + '</pre>');
          }
        });
      }
    },
    displayError: function displayError(string) {
      var error = $('<div class="messages messages--error"></div>').html(string);
      $(this.element).before(error).hide();

      if (this.errorCallback) {
        this.errorCallback(this);
      }
    }
  });

  /*
   * Batch display highly based on core's batch.js. Differences:
   *
   * 1. Completion does not redirect to a different URI.
   * 2. Batch op arguments are not passed.
   */
  Drupal.behaviors.upgradeStatusBatch = {
    attach: function attach(context, settings) {
      var batch = settings.batch;
      var $progress = $('[data-drupal-progress]').once('upgradeStatusBatch');
      var progressBar = void 0;

      function updateCallback(progress, pb) {
        // Update table data as it comes in.
        if (progress.result && progress.result.length) {
          $('table tr' + progress.result[0])
            .removeClass('no-known-error known-errors not-scanned')
            .addClass(progress.result[1]);
          $('table tr' + progress.result[0] + ' td:nth-child(2)')
            .replaceWith('<td>' + progress.result[2] + '</td>');
          var newNodes = $('<td>' + progress.result[3]+ '</td>');
          $('table tr' + progress.result[0] + ' td:nth-child(3)')
              .replaceWith(newNodes);

          // @todo Passed document as core active links JS seems to break on
          //   elements that don't have a querySelectorAll() and would break
          //   the dialog behavior.
          Drupal.attachBehaviors(document, window.drupalSettings);
        }

        if (progress.percentage == 100) {
          pb.stopMonitoring();
          // @todo actually display something useful
          $('.progress').remove();
          // Enable the submit button again.
          $('.form-submit').removeClass('is-disabled').removeAttr('disabled');
          $('#edit-cancel').remove();
          $('form').prepend('<div class="report-date">' + progress.date + '</div>');
        }
      }

      function errorCallback(pb) {
        $progress.prepend($('<p class="error"></p>').json(batch.errorMessage));
        $('#wait').hide();
      }

      if ($progress.length) {
        progressBar = new Drupal.UpgradeStatusProgressBar('progress', updateCallback, 'POST', errorCallback);
        progressBar.setProgress(-1, batch.initMessage);
        progressBar.startMonitoring(batch.uri, 10);
        $progress.replaceWith(progressBar.element);
      }
    }
  };
})(jQuery, Drupal);
