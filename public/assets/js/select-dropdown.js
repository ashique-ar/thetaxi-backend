$(document).ready(function () {

  function initializeDropdown(dropdown) {
    var searchInput = dropdown.find(".dropdown-search");
    var dropdownList = dropdown.find(".dropdown-list");
    var listItems = dropdownList.find("li:not(.not-found)");

    searchInput.on("input", function () {
      var filter = $(this).val().toLowerCase();
      var matchCount = filterResults(listItems, filter);
      toggleDropdownList(filter, matchCount);
    });

    searchInput.on("focus", function () {
      var filter = $(this).val().toLowerCase();
      var matchCount = filterResults(listItems, filter);
      toggleDropdownList(filter, matchCount);
    });

    searchInput.on("blur", function () {
      setTimeout(() => {
        var inputText = searchInput.val().toLowerCase();
        var itemExists = listItems.toArray().some(el => $(el).text().toLowerCase() === inputText);

        if (!itemExists) {
          searchInput.val("");
          filterResults(listItems, "");
          toggleDropdownList("", 0);
        }

        dropdownList.hide();
      }, 150);
    });

    dropdownList.on("mousedown", "li:not(.not-found)", function () {
      var selectedItem = $(this).text();
      var selectedItemId = $(this).data("id");
      searchInput.val(selectedItem);
      dropdownList.hide();
      handleItemSelected(selectedItem, selectedItemId);
    });

    searchInput.on("keydown", function (event) {
      if (event.key === "Enter") {
        event.preventDefault();
        var firstVisibleItem = dropdownList.find("li:not(.not-found):visible").first();
        if (firstVisibleItem.length) {
          var selectedItem = firstVisibleItem.text();
          var selectedItemId = firstVisibleItem.data("id");
          searchInput.val(selectedItem);
          dropdownList.hide();
          handleItemSelected(selectedItem, selectedItemId);
        }
      }
    });

    searchInput.on("keyup", function (event) {
      if (event.key === "Escape") {
        searchInput.val("");
        var matchCount = filterResults(listItems, "");
        toggleDropdownList("", matchCount);
      }
    });

    function filterResults(listItems, filter) {
      var limit = 10;
      var matchCount = 0;

      listItems.each(function () {
        var match = $(this).text().toLowerCase().includes(filter);
        if (match && matchCount < limit) {
          $(this).show();
          matchCount++;
        } else {
          $(this).hide();
        }
      });

      return matchCount;
    }

    function toggleDropdownList(filter, matchCount) {
      var notFoundItem = dropdownList.find(".not-found");

      if (matchCount > 0) {
        dropdownList.show();
        notFoundItem.hide();
      } else if (filter.length > 0) {
        dropdownList.show();
        notFoundItem.show();
      } else {
        dropdownList.hide();
      }
    }
  }

  function handleItemSelected(item, itemId) {
    console.log("Item selected:", item, "with ID:", itemId);
    // You can handle selection logic here
  }

  initializeDropdown($("#search_vendor"));
  initializeDropdown($("#search_customer"));

});
