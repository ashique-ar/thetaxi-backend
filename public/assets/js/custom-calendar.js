
  $('.trip-search-form input[name="dates"]').on("focus", function () {
    $(this)
      .closest(".trip-search-form")
      .find(".calendar-wrapper.full-date")
      .fadeIn(0);
    $(this)
      .closest(".trip-search-form")
      .find(".field-location .field-location__result")
      .fadeOut(0);
  });
  const uniqueId = "calendar-" + Math.random().toString(36).substr(2, 9);


  let currentDate = new Date(); // Start with the current date
  currentDate.setHours(0, 0, 0, 0);
  let displayDate = new Date(
    currentDate.getFullYear(),
    currentDate.getMonth(),
    1
  ); // First day of the display month

  // Parse the pricing data and month names from attributes
  let pricingData = [];
  let monthNames = [
    "January",
    "February",
    "March",
    "April",
    "May",
    "June",
    "July",
    "August",
    "September",
    "October",
    "November",
    "December",
  ];
  if ($(".calendar-wrapper.full-date").length > 0) {
    pricingData = JSON.parse(
      $(".calendar-wrapper.full-date").attr("data-dates") || "[]"
    );
  }
  if ($(".calendar-wrapper.full-date").attr("data-months-name")) {
    monthNames = JSON.parse(
      $(".calendar-wrapper.full-date").attr("data-months-name")
    );
  }

  let $scope = $(document);

  let startDate = null;
  let endDate = null;

function renderCalendar(date, calendarId, monthYearId) {
        const month = date.getMonth();
        const year = date.getFullYear();
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        // Update month and year display
        $(`.calendar-wrapper.full-date #${monthYearId}`).text(
          `${monthNames[month]} ${year}`
        );

        // Clear previous dates
        $(`.calendar-wrapper.full-date #${calendarId}`).empty();

        // Determine first day of the month
        const firstDay = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();

        // Fill initial empty spaces if the month does not start on Monday
        for (let i = 0; i < (firstDay || 7) - 1; i++) {
          $(`.calendar-wrapper.full-date #${calendarId}`).append("<div></div>");
        }

        // Populate days with date information
        for (let day = 1; day <= daysInMonth; day++) {
          const dayDate = new Date(year, month, day);
          const formattedDate = `${dayDate.getFullYear()}-${String(
            dayDate.getMonth() + 1
          ).padStart(2, "0")}-${String(dayDate.getDate()).padStart(2, "0")}`;
            const priceForDate = getPriceForDate(dayDate);
            const offerPriceForDate = getOfferPriceFromData(dayDate);
            const dateDiv = $(`
                <div class="date" data-date="${formattedDate}">
                <span>${day}</span>
                <div class="price">${priceForDate}</div>
                <del>${offerPriceForDate}</del>
                </div>
            `);
          // Highlight today's date
          if (
            day === currentDate.getDate() &&
            month === currentDate.getMonth() &&
            year === currentDate.getFullYear()
          ) {
            dateDiv.addClass("today");
          }

          // Highlight selected range
          if (
            startDate &&
            endDate &&
            new Date(formattedDate) >= new Date(startDate) &&
            new Date(formattedDate) <= new Date(endDate)
          ) {
            dateDiv.addClass("in-range");
            if (formattedDate === startDate) {
              dateDiv.addClass("first-range");
            }
            if (formattedDate === endDate) {
              dateDiv.addClass("last-range");
            }
          } else if (formattedDate === startDate || formattedDate === endDate) {
            dateDiv.addClass("is-selected");
          }

          // Disable past dates
          if (dayDate <= today) {
            dateDiv.addClass("disabled").attr("aria-disabled", "true");
          }

          $(`.calendar-wrapper.full-date #${calendarId}`).append(dateDiv);
        }
      }

  function updateCalendars() {
    // Always render the current month into calendar-prev
    renderCalendar(displayDate, "calendar-dates-prev", "month-year-prev");

    // Check if the screen is larger than 992px before displaying the next month
    if (window.innerWidth >= 768) {
      const nextMonthDate = new Date(
        displayDate.getFullYear(),
        displayDate.getMonth() + 1,
        1
      );
      renderCalendar(
        nextMonthDate,
        "calendar-dates-next",
        "month-year-next"
      );
      $("#calendar-next").show();
    } else {
      $("#calendar-next").hide();
    }
  }

  function updateInputField(formattedDate) {
    const $display2 = jQuery(".booking-modal  .selected-date");
    $display2.html(`<h6>${moment(formattedDate).format("D MMMM")}</h6><span>Booking Date</span>`);
  }

  // Event listeners for month navigation
  $(".calendar-wrapper .prev-month").on("click", function (e) {
    e.preventDefault();
    displayDate.setMonth(displayDate.getMonth() - 1);
    updateCalendars();
  });

  $(".calendar-wrapper .next-month").on("click", function (e) {
    e.preventDefault();
    displayDate.setMonth(displayDate.getMonth() + 1);
    updateCalendars();
  });


  // Handle date selection for range
    $scope.on("click", ".calendar .date:not(.disabled)", function (e) {
      const selectedDate = $(this).attr('data-date');
      let month = 8, year = 2025;

      // Check if the clicked date is in the left (previous) calendar or the right (next) calendar
      if ($(this).closest(`#${uniqueId}-dates-prev`).length) {
        month = String(displayDate.getMonth() + 1).padStart(2, "0");
        year = displayDate.getFullYear();
      } else if ($(this).closest(`#${uniqueId}-dates-next`).length) {
        const nextMonthDate = new Date(
          displayDate.getFullYear(),
          displayDate.getMonth() + 1,
          1
        );
        month = String(nextMonthDate.getMonth() + 1).padStart(2, "0");
        year = nextMonthDate.getFullYear();
      }

      // Format date as "Jul 03, 2025"
      const dateObj = new Date(`${selectedDate}`);
      
      const formattedDate = dateObj.toLocaleDateString("en-US", {
        year: "numeric",
        month: "short",
        day: "2-digit",
      });

      // Update UI and input with selected date
      $(".calendar .date").removeClass("today");
      $(".calendar .date").not(this).removeClass("is-selected");
      $(this).addClass("is-selected");
      var layout = $(this).closest(".booking-form").attr("data-layout");
      if (layout == "01") {
        $(this).closest(".form-field").find(".name").text(formattedDate);
      } else if (layout == "03") {
        $(this).closest(".form-field").find(".choose-date").text(formattedDate);
      }

      $(this).closest(".form-field").removeClass("is-active");
      $(this).closest(".form-field").next().addClass("is-active");
      updateInputField(formattedDate);
    });

  // Initial render
  $(document).ready(function () {
    updateCalendars();
  });