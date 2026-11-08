/**
 * Admin Analytics JavaScript.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

(function ($) {
  "use strict";

  var RFWAnalytics = {
    ticketsChart: null,
    statusChart: null,

    /**
     * Initialize the analytics dashboard.
     */
    init: function () {
      this.bindEvents();
      this.loadData();
    },

    /**
     * Bind event handlers.
     */
    bindEvents: function () {
      $("#rfwc-refresh-analytics").on("click", this.loadData.bind(this));
      $("#rfwc-raffle-filter, #rfwc-date-range-filter").on(
        "change",
        this.loadData.bind(this)
      );
      $("#rfwc-export-csv").on("click", this.exportCSV.bind(this));
    },

    /**
     * Load analytics data via AJAX.
     */
    loadData: function () {
      var self = this;

      // Show loading state.
      $(
        "#stat-total-tickets, #stat-total-revenue, #stat-gift-tickets, #stat-unique-customers"
      ).text("-");
      $("#top-raffles-table, #top-purchasers-table").html(
        '<tr><td colspan="4">' + rfwc_analytics.i18n.loading + "</td></tr>"
      );

      $.ajax({
        url: rfwc_analytics.ajax_url,
        type: "POST",
        data: {
          action: "rfwc_get_analytics_data",
          nonce: rfwc_analytics.nonce,
          raffle_id: $("#rfwc-raffle-filter").val(),
          date_range: $("#rfwc-date-range-filter").val(),
        },
        success: function (response) {
          if (response.success && response.data) {
            self.updateStats(response.data.stats);
            self.updateCharts(response.data.chart_data, response.data.stats, response.data.status_distribution);
            self.updateTopRaffles(response.data.top_raffles);
            self.updateTopPurchasers(response.data.top_purchasers);
          } else {
            self.showError(
              response.data && response.data.message
                ? response.data.message
                : "Failed to load analytics data."
            );
          }
        },
        error: function (xhr, status, error) {
          console.error("Failed to load analytics data:", status, error);
          self.showError(
            "Failed to load analytics data. Please refresh the page."
          );
        },
      });
    },

    /**
     * Update statistics display.
     *
     * @param {Object} stats Statistics data.
     */
    updateStats: function (stats) {
      $("#stat-total-tickets").text(this.formatNumber(stats.total_tickets));
      $("#stat-total-revenue").html(stats.total_revenue);
      $("#stat-gift-tickets").text(
        this.formatNumber(stats.gift_tickets) +
          " (" +
          stats.gift_percentage +
          "%)"
      );
      $("#stat-unique-customers").text(
        this.formatNumber(stats.unique_customers)
      );
    },

    /**
     * Update charts.
     *
     * @param {Object} chartData Chart data.
     * @param {Object} stats     Statistics data.
     */
    updateCharts: function (chartData, stats, statusDistribution) {
      this.updateTicketsChart(chartData);
      this.updateStatusChart(statusDistribution);
    },

    /**
     * Update tickets over time chart.
     *
     * @param {Object} data Chart data.
     */
    updateTicketsChart: function (data) {
      var ctx = document.getElementById("tickets-chart");
      if (!ctx) {
        return;
      }

      // Destroy existing chart.
      if (this.ticketsChart) {
        this.ticketsChart.destroy();
      }

      // Check for empty data.
      if (!data.labels || data.labels.length === 0) {
        ctx.parentElement.innerHTML =
          '<p style="text-align: center; padding: 40px; color: #646970;">' +
          rfwc_analytics.i18n.no_data +
          "</p>";
        return;
      }

      // Gracefully handle missing Chart.js (blocked by security plugin, caching issue, etc.).
      if (typeof Chart === "undefined") {
        ctx.parentElement.innerHTML =
          '<p style="text-align: center; padding: 40px; color: #646970;">' +
          "Chart library unavailable. Stats and tables are still shown above." +
          "</p>";
        return;
      }

      this.ticketsChart = new Chart(ctx, {
        type: "line",
        data: {
          labels: data.labels,
          datasets: [
            {
              label: rfwc_analytics.i18n.tickets_sold,
              data: data.totals,
              borderColor: "#7f54b3",
              backgroundColor: "rgba(127, 84, 179, 0.1)",
              fill: true,
              tension: 0.3,
            },
            {
              label: rfwc_analytics.i18n.gift_purchases,
              data: data.gifts,
              borderColor: "#e91e63",
              backgroundColor: "transparent",
              borderDash: [5, 5],
              tension: 0.3,
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          animation: {
            duration: 1000,
            easing: "easeOutQuart",
          },
          plugins: {
            legend: {
              position: "top",
            },
          },
          scales: {
            y: {
              beginAtZero: true,
              ticks: {
                precision: 0,
              },
            },
          },
        },
      });
    },

    /**
     * Update ticket status distribution chart.
     *
     * @param {Object} dist Status distribution data.
     */
    updateStatusChart: function (dist) {
      var ctx = document.getElementById("status-chart");
      if (!ctx) {
        return;
      }

      if (this.statusChart) {
        this.statusChart.destroy();
      }

      if (!dist) {
        return;
      }

      var total = (dist.active || 0) + (dist.winner || 0) + (dist.cancelled || 0) + (dist.refunded || 0);
      if (total === 0) {
        ctx.parentElement.querySelector("canvas").style.display = "none";
        return;
      }

      if (typeof Chart === "undefined") {
        return;
      }

      ctx.style.display = "block";

      this.statusChart = new Chart(ctx, {
        type: "doughnut",
        data: {
          labels: [
            rfwc_analytics.i18n.status_active,
            rfwc_analytics.i18n.status_winner,
            rfwc_analytics.i18n.status_cancelled,
            rfwc_analytics.i18n.status_refunded,
          ],
          datasets: [
            {
              data: [
                dist.active || 0,
                dist.winner || 0,
                dist.cancelled || 0,
                dist.refunded || 0,
              ],
              backgroundColor: ["#7f54b3", "#00a32a", "#6c757d", "#d63638"],
              borderWidth: 0,
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          animation: {
            duration: 1000,
            easing: "easeOutQuart",
            animateRotate: true,
            animateScale: true,
          },
          plugins: {
            legend: {
              position: "bottom",
            },
          },
          cutout: "60%",
        },
      });
    },

    /**
     * Update top raffles table.
     *
     * @param {Array} raffles Top raffles data.
     */
    updateTopRaffles: function (raffles) {
      var $tbody = $("#top-raffles-table");
      $tbody.empty();

      if (!raffles || raffles.length === 0) {
        $tbody.html(
          '<tr><td colspan="4">' + rfwc_analytics.i18n.no_data + "</td></tr>"
        );
        return;
      }

      raffles.forEach(function (raffle) {
        var statusClass = raffle.status.class || "";
        var $row = $("<tr>");
        var $nameCell = $("<td>");
        $("<a>").attr("href", raffle.edit_url).text(raffle.name).appendTo($nameCell);
        $row.append($nameCell);
        $row.append($("<td>").text(raffle.ticket_count));
        $row.append($("<td>").html(raffle.revenue)); // wc_price() HTML is trusted server-side output.
        $row.append(
          $("<td>").append(
            $("<span>").addClass("rfwc-status-badge " + statusClass).text(raffle.status.label)
          )
        );
        $tbody.append($row);
      });
    },

    /**
     * Update top purchasers table.
     *
     * @param {Array} purchasers Top purchasers data.
     */
    updateTopPurchasers: function (purchasers) {
      var $tbody = $("#top-purchasers-table");
      $tbody.empty();

      if (!purchasers || purchasers.length === 0) {
        $tbody.html(
          '<tr><td colspan="4">' + rfwc_analytics.i18n.no_data + "</td></tr>"
        );
        return;
      }

      purchasers.forEach(function (purchaser) {
        var $row = $("<tr>");
        $row.append($("<td>").text(purchaser.name));
        $row.append($("<td>").text(purchaser.ticket_count));
        $row.append($("<td>").html(purchaser.spent)); // wc_price() HTML is trusted server-side output.
        $row.append($("<td>").text(purchaser.gift_count));
        $tbody.append($row);
      });
    },

    /**
     * Export analytics to CSV.
     */
    exportCSV: function () {
      var $btn = $("#rfwc-export-csv");
      $btn.prop("disabled", true).text(rfwc_analytics.i18n.loading);

      $.ajax({
        url: rfwc_analytics.ajax_url,
        type: "POST",
        data: {
          action: "rfwc_export_analytics",
          nonce: rfwc_analytics.nonce,
          raffle_id: $("#rfwc-raffle-filter").val(),
          date_range: $("#rfwc-date-range-filter").val(),
        },
        success: function (response) {
          if (response.success && response.data.csv_content) {
            // Create blob download from CSV content.
            var blob = new Blob([response.data.csv_content], {
              type: "text/csv;charset=utf-8;",
            });
            var url = URL.createObjectURL(blob);
            var link = document.createElement("a");
            link.href = url;
            link.download = response.data.filename || "raffle-analytics.csv";
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
          }
          $btn.prop("disabled", false).text("Export CSV");
        },
        error: function () {
          alert("Export failed. Please try again.");
          $btn.prop("disabled", false).text("Export CSV");
        },
      });
    },

    /**
     * Show an error message in the loading areas so they don't stay on "Loading..." forever.
     *
     * @param {string} message Error message to display.
     */
    showError: function (message) {
      var errorHtml =
        '<span style="color: #d63638;">' +
        $("<span>").text(message).html() +
        "</span>";

      $(
        "#stat-total-tickets, #stat-total-revenue, #stat-gift-tickets, #stat-unique-customers"
      ).html(errorHtml);
      $("#top-raffles-table, #top-purchasers-table").html(
        '<tr><td colspan="4" style="color: #d63638;">' +
          $("<span>").text(message).html() +
          "</td></tr>"
      );
    },

    /**
     * Format number with comma separators.
     *
     * @param {number} num The number to format.
     * @return {string} Formatted number.
     */
    formatNumber: function (num) {
      return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    },
  };

  // Initialize on document ready.
  $(function () {
    RFWAnalytics.init();
  });
})(jQuery);
