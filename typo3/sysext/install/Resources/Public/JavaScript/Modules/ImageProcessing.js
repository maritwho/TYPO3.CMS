/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

/**
 * Module: TYPO3/CMS/Install/ImageProcessing
 */
define(['jquery',
  'TYPO3/CMS/Install/Router',
  'TYPO3/CMS/Install/FlashMessage',
  'TYPO3/CMS/Install/ProgressBar',
  'TYPO3/CMS/Install/InfoBox',
  'TYPO3/CMS/Install/Severity',
  'bootstrap'
], function($, Router, FlashMessage, ProgressBar, InfoBox, Severity) {
  'use strict';

  return {
    selectorGridderOpener: 't3js-imageProcessing-open',
    selectorExecuteTrigger: '.t3js-imageProcessing-execute',
    selectorTestContainer: '.t3js-imageProcessing-twinContainer',
    selectorTwinImageTemplate: '.t3js-imageProcessing-twinImage-template',
    selectorCommandContainer: '.t3js-imageProcessing-command',
    selectorCommandText: '.t3js-imageProcessing-command-text',
    selectorTwinImages: '.t3js-imageProcessing-images',

    initialize: function() {
      var self = this;

      // Load main content on first open
      $(document).on('cardlayout:card-opened', function(event, $card) {
        if ($card.hasClass(self.selectorGridderOpener) && !$card.data('isInitialized')) {
          $card.data('isInitialized', true);
          self.runTests();
        }
      });

      $(document).on('click', this.selectorExecuteTrigger, function(e) {
        e.preventDefault();
        self.runTests();
      });
    },

    runTests: function() {
      var self = this;
      var $twinImageTemplate = $(this.selectorTwinImageTemplate);
      $(this.selectorTestContainer).each(function() {
        var $container = $(this);
        var testType = $container.data('test');
        var message = InfoBox.render(Severity.loading, 'Loading...', '');
        $container.empty().html(message);
        $.ajax({
          url: Router.getUrl(testType),
          cache: false,
          success: function(data) {
            if (data.success === true) {
              $container.empty();
              if (Array.isArray(data.status)) {
                data.status.forEach(function(element) {
                  var message = InfoBox.render(element.severity, element.title, element.message);
                  $container.append(message);
                });
              }
              var $aTwin = $twinImageTemplate.clone();
              $aTwin.removeClass('t3js-imageProcessing-twinImage-template');
              if (data.fileExists === true) {
                $aTwin.find('img.reference').attr('src', data.referenceFile);
                $aTwin.find('img.result').attr('src', data.outputFile);
                $aTwin.find(self.selectorTwinImages).show();
              }
              if (Array.isArray(data.command) && data.command.length > 0) {
                $aTwin.find(self.selectorCommandContainer).show();
                var commandText = [];
                data.command.forEach(function(element) {
                  commandText.push('<strong>Command:</strong>\n' + element[1]);
                  if (element.length === 3) {
                    commandText.push('<strong>Result:</strong>\n' + element[2]);
                  }
                });
                $aTwin.find(self.selectorCommandText).html(commandText.join('\n'));
              }
              $container.append($aTwin);
            }
          },
          error: function(xhr) {
            Router.handleAjaxError(xhr);
          }
        });
      });
    }
  };
});
