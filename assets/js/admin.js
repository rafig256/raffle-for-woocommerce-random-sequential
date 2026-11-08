/**
 * Raffle for WooCommerce - Admin Scripts
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

/* global jQuery, rfwc_admin_params */

(function ($) {
  "use strict";

  /**
   * Toggle prize fields based on selected prize type.
   *
   * @param {string} prizeType The selected prize type value.
   */
  function togglePrizeFields(prizeType) {
    $(".rfwc-prize-product-fields").toggleClass(
      "rfwc-hidden",
      prizeType !== "product"
    );
    $(".rfwc-prize-credit-fields").toggleClass(
      "rfwc-hidden",
      prizeType !== "store_credit"
    );
    $(".rfwc-prize-custom-fields").toggleClass(
      "rfwc-hidden",
      prizeType !== "custom"
    );
  }

  /**
   * Update prize tier visibility based on number of winners.
   *
   * @param {number} numWinners The number of winners selected.
   */
  function updatePrizeTiers(numWinners) {
    var maxTiers =
      (typeof rfwc_admin_params !== "undefined" &&
        parseInt(rfwc_admin_params.max_prize_tiers, 10)) ||
      20;

    numWinners = parseInt(numWinners, 10) || 1;
    numWinners = Math.max(1, Math.min(maxTiers, numWinners));

    for (var tier = 1; tier <= maxTiers; tier++) {
      var shouldShow = tier <= numWinners;

      $("#_rfwc_prize_product_" + tier)
        .closest(".form-field")
        .toggle(shouldShow);

      $("#_rfwc_prize_credit_" + tier)
        .closest(".form-field")
        .toggle(shouldShow);

      $("#_rfwc_prize_description_" + tier)
        .closest(".form-field")
        .toggle(shouldShow);

      $("#_rfwc_prize_value_" + tier)
        .closest(".form-field")
        .toggle(shouldShow);
    }
  }

  /**
   * Toggle compliance field visibility in the product editor.
   *
   * Shows/hides fields based on the skill question enable, source, and type dropdowns.
   */
  function toggleComplianceFields() {
    var skillEnabled = $("#_rfwc_enable_skill_question").val();
    var isSkillOn = skillEnabled === "yes" || (skillEnabled === "" && rfwc_admin_params.global_skill_enabled === "yes");

    // Question source, custom fields — only show when skill question is on.
    $("#_rfwc_skill_question_source").closest(".form-field").toggle(isSkillOn);

    var source = $("#_rfwc_skill_question_source").val();
    var isCustom = isSkillOn && source === "custom";
    var isGlobal = isSkillOn && source !== "custom";

    $("#_rfwc_skill_question").closest(".form-field").toggle(isCustom);
    $("#_rfwc_skill_question_type").closest(".form-field").toggle(isCustom);
    $("#_rfwc_skill_answer").closest(".form-field").toggle(isCustom);

    var questionType = $("#_rfwc_skill_question_type").val();
    var showChoices = isCustom && questionType === "multiple_choice";
    $("#_rfwc_skill_choices").closest(".form-field").toggle(showChoices);

    // Show pool question selector only for global pool.
    $(".rfwc-pool-question-field").toggle(isGlobal);

    // Free entry text — only show when free entry is not "no".
    var freeEnabled = $("#_rfwc_enable_free_entry").val();
    var isFreeOn = freeEnabled === "yes" || (freeEnabled === "" && rfwc_admin_params.global_free_enabled === "yes");
    $("#_rfwc_free_entry_text").closest(".form-field").toggle(isFreeOn);
  }

  var RFWAdmin = {
    init: function () {
      this.bindEvents();
      this.initProductType();
      this.initDatePickers();
      this.initPrizeTypeToggle();
      this.initPrizeTiers();
      this.initComplianceFields();
      this.initQuestionPoolRepeater();
    },

    bindEvents: function () {
      $("#product-type").on("change", this.onProductTypeChange);

      // Use event delegation for prize type change (jQuery).
      $(document).on("change", "#_rfwc_prize_type", function () {
        togglePrizeFields($(this).val());
      });

      // Handle number of winners change.
      $(document).on("change", "#_rfwc_number_of_winners", function () {
        updatePrizeTiers($(this).val());
      });

      // Also bind native event for compatibility.
      document.addEventListener("change", function (e) {
        if (e.target && e.target.id === "_rfwc_prize_type") {
          togglePrizeFields(e.target.value);
        }
        if (e.target && e.target.id === "_rfwc_number_of_winners") {
          updatePrizeTiers(e.target.value);
        }
      });

      $(document).on("click", ".rfwc-draw-winner", this.onDrawWinner);

      // Compliance field toggles in product editor.
      $(document).on(
        "change",
        "#_rfwc_enable_skill_question, #_rfwc_skill_question_source, #_rfwc_skill_question_type, #_rfwc_enable_free_entry",
        function () {
          toggleComplianceFields();
        }
      );
    },

    initProductType: function () {
      $("#product-type").trigger("change");
    },

    initDatePickers: function () {
      $("#raffle_product_data .date-picker").datepicker({
        dateFormat: "yy-mm-dd",
        numberOfMonths: 1,
        showButtonPanel: true,
      });
    },

    initPrizeTypeToggle: function () {
      // Initialize prize type toggle on page load.
      var $prizeType = $("#_rfwc_prize_type");
      if ($prizeType.length) {
        togglePrizeFields($prizeType.val());
      }
    },

    initPrizeTiers: function () {
      // Initialize prize tier visibility on page load.
      var $numWinners = $("#_rfwc_number_of_winners");
      if ($numWinners.length) {
        updatePrizeTiers($numWinners.val());
      }
    },

    initComplianceFields: function () {
      // Initialize compliance field visibility on page load.
      if ($("#_rfwc_enable_skill_question").length) {
        toggleComplianceFields();
      }

      // Initialize Select2 on the pool question dropdown for searchable selection.
      var $poolSelect = $("#_rfwc_skill_question_pool_index");
      if ($poolSelect.length && $.fn.select2) {
        $poolSelect.select2({
          width: "100%",
          allowClear: true,
          placeholder: $poolSelect.data("placeholder") || "",
          dropdownAutoWidth: true,
        });
      }
    },

    initQuestionPoolRepeater: function () {
      var $wrapper = $(".rfwc-question-pool-wrapper");
      if (!$wrapper.length) {
        return;
      }

      var questionIndex =
        $wrapper.find(".rfwc-question-pool-item").length;

      // Add question button.
      $wrapper.on("click", ".rfwc-add-question", function () {
        var template = $("#tmpl-rfwc-question-pool-item").html();
        template = template.replace(/\{\{INDEX\}\}/g, questionIndex);
        $wrapper.find(".rfwc-question-pool-items").append(template);
        questionIndex++;
      });

      // Remove question button.
      $wrapper.on("click", ".rfwc-remove-question", function () {
        $(this).closest(".rfwc-question-pool-item").remove();
      });

      // Toggle MC choices visibility per question type.
      $wrapper.on("change", ".rfwc-question-type-select", function () {
        var $choicesWrap = $(this)
          .closest(".rfwc-question-pool-item")
          .find(".rfwc-question-choices-wrap");
        $choicesWrap.toggle($(this).val() === "multiple_choice");
      });
    },

    onProductTypeChange: function () {
      var isRaffle = $(this).val() === "raffle";

      $(".show_if_raffle").toggle(isRaffle);
      $(".hide_if_raffle").toggle(!isRaffle);

      if (isRaffle) {
        $("#_virtual").prop("checked", true).trigger("change");
        // Force the General tab visible in the sidebar and force pricing
        // fields visible inside the General panel. WooCommerce hides
        // .show_if_simple / .pricing for unknown product types like raffle.
        // Do NOT force #general_product_data itself visible — WooCommerce
        // shows panels via tab clicks; forcing it would stack the General
        // panel on top of whichever tab is active.
        $(".general_options").show();
        $("li.general_tab").show();
        $(".show_if_simple").show();
        $(".pricing").show();
      }
    },

    onDrawWinner: function (e) {
      e.preventDefault();

      if (!window.confirm(rfwc_admin_params.i18n.confirm_draw)) {
        return;
      }

      var $button = $(this);
      var raffleId = $button.data("raffle-id");

      $button.prop("disabled", true).addClass("updating-message");

      $.ajax({
        url: rfwc_admin_params.ajax_url,
        type: "POST",
        data: {
          action: "rfwc_draw_winner",
          raffle_id: raffleId,
          nonce: rfwc_admin_params.nonce,
        },
        success: function (response) {
          if (response.success) {
            window.location.reload();
          } else {
            window.alert(response.data.message || "Error drawing winner.");
            $button.prop("disabled", false).removeClass("updating-message");
          }
        },
        error: function () {
          window.alert("Error drawing winner. Please try again.");
          $button.prop("disabled", false).removeClass("updating-message");
        },
      });
    },

  };

  /**
   * Bulk discount tier add/remove handlers.
   */
  function initBulkTierFields() {
    $( '.rfwc-bulk-add-tier' ).on( 'click', function() {
      var $hidden = $( '.rfwc-bulk-tier-row.rfwc-hidden' ).first();
      if ( $hidden.length ) {
        $hidden.removeClass( 'rfwc-hidden' );
      }
      if ( ! $( '.rfwc-bulk-tier-row.rfwc-hidden' ).length ) {
        $( this ).prop( 'disabled', true );
      }
    });

    $( document ).on( 'click', '.rfwc-bulk-remove-tier', function() {
      var $row = $( this ).closest( '.rfwc-bulk-tier-row' );
      $row.find( 'input' ).val( '' );
      $row.addClass( 'rfwc-hidden' );
      $( '.rfwc-bulk-add-tier' ).prop( 'disabled', false );
    });
  }

  /**
   * Instant Win field visibility toggles.
   */
  function initInstantWinFields() {
    var $enabledCheckbox = $( '#_rfwc_instant_win_enabled' );
    var $modeSelect      = $( '#_rfwc_instant_win_mode' );
    var $iwOnlyCheckbox  = $( '#_rfwc_instant_win_only' );
    var $autoDrawCheckbox = $( '#_rfwc_auto_draw' );

    function toggleIWFields() {
      var enabled = $enabledCheckbox.is( ':checked' );
      var mode    = $modeSelect.val();
      var iwOnly  = $iwOnlyCheckbox.is( ':checked' );

      $( '.rfwc-iw-field' ).toggleClass( 'rfwc-hidden', ! enabled );

      if ( enabled ) {
        $( '.rfwc-iw-probability-field' ).toggleClass( 'rfwc-hidden', 'probability' !== mode );
        $( '.rfwc-iw-interval-field' ).toggleClass( 'rfwc-hidden', 'guaranteed' !== mode );
      }

      if ( enabled && iwOnly ) {
        $autoDrawCheckbox.prop( 'checked', false ).prop( 'disabled', true );
        $autoDrawCheckbox.closest( '.form-field, p' ).css( 'opacity', '0.4' );
      } else {
        $autoDrawCheckbox.prop( 'disabled', false );
        $autoDrawCheckbox.closest( '.form-field, p' ).css( 'opacity', '' );
      }
    }

    $enabledCheckbox.on( 'change', toggleIWFields );
    $modeSelect.on( 'change', toggleIWFields );
    $iwOnlyCheckbox.on( 'change', toggleIWFields );
    toggleIWFields();
  }

  $(document).ready(function () {
    RFWAdmin.init();
    initBulkTierFields();
    initInstantWinFields();

    // Handle welcome notice dismissal.
    $('.notice[data-notice="rfwc-welcome"]').on(
      "click",
      ".notice-dismiss",
      function () {
        if (
          typeof rfwc_admin_params !== "undefined" &&
          rfwc_admin_params.dismiss_nonce
        ) {
          $.post(rfwc_admin_params.ajax_url, {
            action: "rfwc_dismiss_welcome_notice",
            nonce: rfwc_admin_params.dismiss_nonce,
          });
        }
      }
    );
  });
})(jQuery);
