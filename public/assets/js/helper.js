function getPriceForDate(date) {
    // Convert date to YYYY-MM-DD format for comparison
    const dateString = date.toISOString().split("T")[0];
    const dayOfWeek = date
      .toLocaleDateString("en-US", { weekday: "long" })
      .toLowerCase();
    var price = "-";

    // Find the price range that includes the current date
    for (let range of pricingData) {
      const startDate = new Date(range.start_date);
      if (range.end_date == "no_end_date") {
        range.end_date = "9999-12-31";
      }
      const endDate = new Date(range.end_date);
      startDate.setHours(0, 0, 0, 0);
      endDate.setHours(0, 0, 0, 0);

      // If the date is within the start and end range, check trip_days
      if (date >= startDate && date <= endDate) {
        // Check if the day of the week is in trip_days
        if (range.trip_days.includes(dayOfWeek)) {
          price = range.format_price;
        }
      }
    }
    // Return default price if no matching range is found
    return price;
}
function getOfferPriceFromData(date) {
  // Convert date to YYYY-MM-DD format for comparison
  const dateString = date.toISOString().split("T")[0];
  const dayOfWeek = date
    .toLocaleDateString("en-US", { weekday: "long" })
    .toLowerCase();
  var offerPrice = "-";

  // Find the price range that includes the current date
  for (let range of pricingData) {
    const startDate = new Date(range.start_date);
    if (range.end_date == "no_end_date") {
      range.end_date = "9999-12-31";
    }
    const endDate = new Date(range.end_date);
    startDate.setHours(0, 0, 0, 0);
    endDate.setHours(0, 0, 0, 0);

    // If the date is within the start and end range, check trip_days
    if (date >= startDate && date <= endDate) {
      // Check if the day of the week is in trip_days
      if (range.trip_days.includes(dayOfWeek)) {
        offerPrice = range.format_offer_price || "-";
      }
    }
  }

  // Return default offer price if no matching range is found
  return offerPrice;
}
