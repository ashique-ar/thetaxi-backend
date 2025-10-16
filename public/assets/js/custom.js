(function ($) {
  ("use strict");

  $(".sidebar-button").on("click", function () {
    $(this).toggleClass("active");
  });

  const sidebarButton = document.querySelector(".sidebar-button");

  if (sidebarButton) {
    sidebarButton.addEventListener("click", () => {
      document.querySelector(".main-menu").classList.toggle("show-menu");
    });
  }

  $(".menu-close-btn").on("click", function () {
    $(".main-menu").removeClass("show-menu");
  });

  // sidebar
  $(".right-sidebar-button").on("click", function () {
    $(".right-sidebar-menu").addClass("show-right-menu");
  });
  $(".right-sidebar-close-btn").on("click", function () {
    $(".right-sidebar-menu").removeClass("show-right-menu");
  });

  $(".menu-btn").on("click", function () {
    $(".sidebar-menu").addClass("active");
  });
  $(".sidebar-menu-close").on("click", function () {
    $(".sidebar-menu").removeClass("active");
  });

  jQuery(".dropdown-icon").on("click", function () {
    jQuery(this).toggleClass("active").next("ul, .mega-menu").slideToggle();
    jQuery(this).parent().siblings().children("ul, .mega-menu").slideUp();
    jQuery(this).parent().siblings().children(".active").removeClass("active");
  });
  jQuery(".dropdown-icon2").on("click", function () {
    jQuery(this).toggleClass("active").next(".submenu-list").slideToggle();
    jQuery(this).parent().siblings().children(".submenu-list").slideUp();
    jQuery(this).parent().siblings().children(".active").removeClass("active");
  });

  // FancyBox Js
  $('[data-fancybox="gallery-01"]').fancybox({
    buttons: ["close"],
    loop: false,
    protect: true,
  });
  $('[data-fancybox="video-player"]').fancybox({
    buttons: ["close"],
    loop: false,
    protect: true,
  });

  $(".location-card").each(function (index) {
    const uniqueGroup = "images-" + index;

    // Update each image inside .image-album-wrap with a unique data-fancybox
    $(this).find(".image-album-wrap a").attr("data-fancybox", uniqueGroup);

    // Update button click event to open only its own first image
    $(this)
      .find(".img-album-btn")
      .on("click", function () {
        startedFromIndexPage = true;
        $(this)
          .closest(".location-card")
          .find(`a[data-fancybox="${uniqueGroup}"]`)
          .first()
          .trigger("click");

        $.fancybox.getInstance().SlideShow.toggle();
      });
  });

  var startedFromIndexPage = false;

  $(document).on("click", ".StartSlideShowFirstImage", function () {
    startedFromIndexPage = true;
    $('a[data-fancybox="images"]').first().trigger("click");
    $.fancybox.getInstance().SlideShow.toggle();
  });

  $('[data-fancybox="images"]').fancybox({
    fullScreen: {
      autoStart: true,
    },
    buttons: ["slideShow", "share", "close"],
    onSlideShowChange: function (instance, current, active) {
      console.info("SlideShow active? " + active);
      if (active && !startedFromIndexPage) {
        instance.next();
      }
      startedFromIndexPage = false;
    },
  });

  // sticky header
  window.addEventListener("scroll", function () {
    const header = document.querySelector("header.header-area");
    if (header) {
      header.classList.toggle("sticky", window.scrollY > 0);
    }
  });

  //Counter up
  $(".counter").counterUp({
    delay: 10,
    time: 1000,
  });

  // Home1 Offer Slider
  var swiper = new Swiper(".home1-offer-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 24,
    autoplay: {
      delay: 2500, // Autoplay duration in milliseconds
      disableOnInteraction: false,
    },
    pagination: {
      el: ".swiper-pagination1",
      clickable: true,
    },
    breakpoints: {
      280: {
        slidesPerView: 1,
      },
      386: {
        slidesPerView: 1,
      },
      576: {
        slidesPerView: 1,
      },
      768: {
        slidesPerView: 2,
      },
      992: {
        slidesPerView: 3,
      },
      1200: {
        slidesPerView: 3,
      },
      1400: {
        slidesPerView: 3,
      },
    },
  });
  // Select all slider elements with the same class
  document
    .querySelectorAll(".home1-destination-slider")
    .forEach((slider, index) => {
      // Add unique pagination class
      $(slider)
        .next(".slider-pagi-wrap")
        .children(".home1-destination-pagi")
        .addClass(`home1-destination-pagi-${index}`);

      setTimeout(() => {
        new Swiper(slider, {
          slidesPerView: 1,
          speed: 1500,
          spaceBetween: 24,
          autoplay: {
            delay: 2500,
            disableOnInteraction: false,
            pauseOnMouseEnter: true,
          },
          pagination: {
            el: `.home1-destination-pagi-${index}`,
            clickable: true,
          },
          breakpoints: {
            280: {
              slidesPerView: 1,
            },
            386: {
              slidesPerView: 1,
            },
            576: {
              slidesPerView: 2,
            },
            768: {
              slidesPerView: 3,
              spaceBetween: 15,
            },
            992: {
              slidesPerView: 4,
              spaceBetween: 15,
            },
            1200: {
              slidesPerView: 4,
            },
            1400: {
              slidesPerView: 4,
            },
          },
        });
      }, 0);
    });
  // Select all slider elements with the same class
  document
    .querySelectorAll(".package-card-img-slider")
    .forEach((slider, index) => {
      // Add unique pagination class
      $(slider)
        .next(".slider-pagi-wrap")
        .children(".package-card-img-pagi")
        .addClass(`package-card-img-pagi-${index}`);
      const nextBtn = slider.parentElement.querySelector(
        ".package-img-slider-next"
      );
      const prevBtn = slider.parentElement.querySelector(
        ".package-img-slider-prev"
      );

      setTimeout(() => {
        new Swiper(slider, {
          slidesPerView: 1,
          speed: 1500,
          spaceBetween: 24,
          effect: "fade",
          fadeEffect: {
            crossFade: true,
          },
          autoplay: {
            delay: 1500,
            disableOnInteraction: false,
          },
          pagination: {
            el: `.package-card-img-pagi-${index}`,
            clickable: true,
          },
          navigation: {
            nextEl: nextBtn,
            prevEl: prevBtn,
          },
        });
      }, 0);
    });
  // Home1 Package Slider
  var swiper = new Swiper(".home1-trip-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 24,
    autoplay: {
      delay: 2500, // Autoplay duration in milliseconds
      pauseOnMouseEnter: true,
      disableOnInteraction: false,
    },
    pagination: {
      el: ".swiper-pagination2",
      clickable: true,
    },
    breakpoints: {
      280: {
        slidesPerView: 1,
      },
      386: {
        slidesPerView: 1,
      },
      576: {
        slidesPerView: 1,
      },
      768: {
        slidesPerView: 2,
        spaceBetween: 15,
      },
      992: {
        slidesPerView: 3,
      },
      1200: {
        slidesPerView: 3,
      },
      1400: {
        slidesPerView: 3,
      },
    },
  });
  // Home1 Blog Slider
  var swiper = new Swiper(".home1-blog-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 24,
    autoplay: {
      delay: 2500, // Autoplay duration in milliseconds
      pauseOnMouseEnter: true,
      disableOnInteraction: false,
    },
    navigation: {
      nextEl: ".blog-slider-next",
      prevEl: ".blog-slider-prev",
    },
    breakpoints: {
      280: {
        slidesPerView: 1,
      },
      386: {
        slidesPerView: 1,
      },
      576: {
        slidesPerView: 1,
      },
      768: {
        slidesPerView: 1,
      },
      992: {
        slidesPerView: 2,
      },
      1200: {
        slidesPerView: 2,
      },
      1400: {
        slidesPerView: 2,
      },
    },
  });
  // Home1 Testimonial Slider
  var swiper = new Swiper(".home1-testimonial-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 24,
    autoplay: {
      delay: 2500, // Autoplay duration in milliseconds
      pauseOnMouseEnter: true,
      disableOnInteraction: false,
    },
    navigation: {
      nextEl: ".testimonial-slider-next",
      prevEl: ".testimonial-slider-prev",
    },
    breakpoints: {
      280: {
        slidesPerView: 1,
      },
      386: {
        slidesPerView: 1,
      },
      576: {
        slidesPerView: 1,
      },
      768: {
        slidesPerView: 2,
        spaceBetween: 15,
      },
      992: {
        slidesPerView: 3,
        spaceBetween: 15,
      },
      1200: {
        slidesPerView: 3,
        spaceBetween: 15,
      },
      1400: {
        slidesPerView: 3,
      },
    },
  });
  // Home2 Top Offer Text Slider
  var swiper = new Swiper(".top-offer-text-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 24,
    autoplay: {
      delay: 2500, // Autoplay duration in milliseconds
      disableOnInteraction: false,
    },
    effect: "fade",
    fadeEffect: {
      crossFade: true,
    },
    navigation: {
      nextEl: ".top-offer-text-slider-next",
      prevEl: ".top-offer-text-slider-prev",
    },
  });
  // Home2 Banner Slider
  var swiper = new Swiper(".home2-banner-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 24,
    autoplay: {
      delay: 3000,
      pauseOnMouseEnter: true,
      disableOnInteraction: false,
    },
    effect: "fade",
    fadeEffect: {
      crossFade: true,
    },
    navigation: {
      nextEl: ".banner-slider-next",
      prevEl: ".banner-slider-prev",
    },
    pagination: {
      el: ".banner-pagination",
      clickable: true,
    },
  });
  // Home2 Destination Slider
  var swiper = new Swiper(".home2-destination-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 24,
    autoplay: {
      delay: 2500, // Autoplay duration in milliseconds
      pauseOnMouseEnter: true,
      disableOnInteraction: false,
    },
    navigation: {
      nextEl: ".destination-slider-next",
      prevEl: ".destination-slider-prev",
    },
    breakpoints: {
      280: {
        slidesPerView: 1,
      },
      386: {
        slidesPerView: 1,
      },
      576: {
        slidesPerView: 2,
      },
      768: {
        slidesPerView: 3,
      },
      992: {
        slidesPerView: 4,
        spaceBetween: 15,
      },
      1200: {
        slidesPerView: 4,
      },
      1400: {
        slidesPerView: 4,
      },
    },
  });
  // Home3 Destination Slider
  var swiper = new Swiper(".home3-destination-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 24,
    autoplay: {
      delay: 2500, // Autoplay duration in milliseconds
      pauseOnMouseEnter: true,
      disableOnInteraction: false,
    },
    navigation: {
      nextEl: ".destination-slider-next",
      prevEl: ".destination-slider-prev",
    },
    pagination: {
      el: ".swiper-pagination1",
      clickable: true,
    },
    breakpoints: {
      280: {
        slidesPerView: 1,
      },
      386: {
        slidesPerView: 1,
      },
      576: {
        slidesPerView: 2,
      },
      768: {
        slidesPerView: 3,
      },
      992: {
        slidesPerView: 4,
        spaceBetween: 15,
      },
      1200: {
        slidesPerView: 4,
        spaceBetween: 15,
      },
      1400: {
        slidesPerView: 4,
      },
    },
  });
  // Home3 Guide Slider
  var swiper = new Swiper(".home3-guide-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 24,
    autoplay: {
      delay: 2500, // Autoplay duration in milliseconds
      pauseOnMouseEnter: true,
      disableOnInteraction: false,
    },
    navigation: {
      nextEl: ".guide-slider-next",
      prevEl: ".guide-slider-prev",
    },
    pagination: {
      el: ".swiper-pagination1",
      clickable: true,
    },
    breakpoints: {
      280: {
        slidesPerView: 1,
      },
      386: {
        slidesPerView: 1,
      },
      576: {
        slidesPerView: 2,
      },
      768: {
        slidesPerView: 3,
      },
      992: {
        slidesPerView: 4,
        spaceBetween: 15,
      },
      1200: {
        slidesPerView: 4,
        spaceBetween: 15,
      },
      1400: {
        slidesPerView: 4,
      },
    },
  });
  // Home3 Testimonial Slider
  var swiper = new Swiper(".home3-testimonial-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 24,
    autoplay: {
      delay: 2500, // Autoplay duration in milliseconds
      disableOnInteraction: false,
    },
    effect: "fade",
    fadeEffect: {
      crossFade: true,
    },
    navigation: {
      nextEl: ".testimonial-slider-next",
      prevEl: ".testimonial-slider-prev",
    },
  });
  // Home4 Offer Slider
  var swiper = new Swiper(".home4-offer-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 24,
    autoplay: {
      delay: 2500, // Autoplay duration in milliseconds
      disableOnInteraction: false,
    },
    pagination: {
      el: ".swiper-pagination2",
      clickable: true,
    },
  });
  // Home4 Testimonial Slider
  var swiper3 = new Swiper(".home4-testimonial-img-slider", {
    spaceBetween: 30,
    freeMode: true,
    watchSlidesProgress: true,
    breakpoints: {
      280: {
        slidesPerView: 2,
      },
      350: {
        slidesPerView: 3,
        spaceBetween: 10,
      },
      576: {
        slidesPerView: 5,
        spaceBetween: 15,
      },
      768: {
        slidesPerView: 5,
        spaceBetween: 15,
      },
      992: {
        slidesPerView: 5,
        spaceBetween: 15,
      },
      1200: {
        slidesPerView: 5,
        spaceBetween: 20,
      },
      1400: {
        slidesPerView: 5,
      },
    },
  });
  var swiper = new Swiper(".home4-testimonial-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 24,
    autoplay: {
      delay: 2500, // Autoplay duration in milliseconds
      disableOnInteraction: false,
    },
    effect: "fade",
    fadeEffect: {
      crossFade: true,
    },
    navigation: {
      nextEl: ".testimonial-slider-next",
      prevEl: ".testimonial-slider-prev",
    },
    thumbs: {
      swiper: swiper3,
    },
  });
  // Home6 Banner Slider
  var swiper = new Swiper(".home6-banner-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 24,
    autoplay: {
      delay: 3000,
      disableOnInteraction: false,
    },
    effect: "fade",
    fadeEffect: {
      crossFade: true,
    },
    navigation: {
      nextEl: ".banner-slider-next",
      prevEl: ".banner-slider-prev",
    },
    pagination: {
      el: ".franctional-pagi1",
      type: "fraction",
    },
  });
  // Home6 Offer Banner Slider
  var swiper4 = new Swiper(".home6-offer-sm-img-slider", {
    spaceBetween: 25,
    slidesPerView: 1,
    freeMode: false,
    watchSlidesProgress: true,
    loop: true,
    breakpoints: {
      280: {
        slidesPerView: 2,
      },
      350: {
        slidesPerView: 3,
        spaceBetween: 10,
      },
      576: {
        slidesPerView: 3,
        spaceBetween: 15,
      },
      768: {
        slidesPerView: 3,
        spaceBetween: 15,
      },
      992: {
        slidesPerView: 3,
        spaceBetween: 15,
      },
      1200: {
        slidesPerView: 3,
        spaceBetween: 20,
      },
      1400: {
        slidesPerView: 3,
      },
    },
  });
  var swiper = new Swiper(".home6-offer-banner-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 24,
    loop: true,
    autoplay: {
      delay: 2500, // Autoplay duration in milliseconds
      disableOnInteraction: false,
    },
    effect: "fade",
    fadeEffect: {
      crossFade: true,
    },
    navigation: {
      nextEl: ".offer-banner-slider-next",
      prevEl: ".offer-banner-slider-prev",
    },
    thumbs: {
      swiper: swiper4,
    },
  });
  // Home6 Activity Banner Slider
  var swiper5 = new Swiper(".home6-activity-sm-img-slider", {
    spaceBetween: 25,
    slidesPerView: 1,
    freeMode: false,
    watchSlidesProgress: true,
    loop: true,
    breakpoints: {
      280: {
        slidesPerView: 2,
      },
      350: {
        slidesPerView: 3,
        spaceBetween: 10,
      },
      576: {
        slidesPerView: 3,
        spaceBetween: 15,
      },
      768: {
        slidesPerView: 3,
        spaceBetween: 15,
      },
      992: {
        slidesPerView: 3,
        spaceBetween: 15,
      },
      1200: {
        slidesPerView: 3,
        spaceBetween: 20,
      },
      1400: {
        slidesPerView: 3,
      },
    },
  });
  var swiper = new Swiper(".home6-activity-banner-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 24,
    loop: true,
    autoplay: {
      delay: 2500, // Autoplay duration in milliseconds
      disableOnInteraction: false,
    },
    effect: "fade",
    fadeEffect: {
      crossFade: true,
    },
    navigation: {
      nextEl: ".offer-banner-slider-next",
      prevEl: ".offer-banner-slider-prev",
    },
    thumbs: {
      swiper: swiper5,
    },
  });
  // Home7 Offer Banner Slider
  var swiper = new Swiper(".home7-offer-banner-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 24,
    autoplay: {
      delay: 3000,
      pauseOnMouseEnter: true,
      disableOnInteraction: false,
    },
    effect: "fade",
    fadeEffect: {
      crossFade: true,
    },
    navigation: {
      nextEl: ".banner-slider-next",
      prevEl: ".banner-slider-prev",
    },
    pagination: {
      el: ".banner-pagination",
      clickable: true,
    },
  });
  // Home7 Guide Slider
  var swiper = new Swiper(".home7-guide-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 24,
    autoplay: {
      delay: 2500, // Autoplay duration in milliseconds
      disableOnInteraction: false,
    },
    navigation: {
      nextEl: ".guide-slider-next",
      prevEl: ".guide-slider-prev",
    },
    pagination: {
      el: ".swiper-pagination1",
      clickable: true,
    },
    breakpoints: {
      280: {
        slidesPerView: 1,
      },
      386: {
        slidesPerView: 1,
      },
      576: {
        slidesPerView: 2,
        spaceBetween: 15,
      },
      768: {
        slidesPerView: 2,
      },
      992: {
        slidesPerView: 3,
        spaceBetween: 15,
      },
      1200: {
        slidesPerView: 4,
        spaceBetween: 15,
      },
      1400: {
        slidesPerView: 4,
      },
    },
  });
  // Home7 Blog Slider
  var swiper = new Swiper(".home7-blog-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 24,
    autoplay: {
      delay: 2500, // Autoplay duration in milliseconds
      disableOnInteraction: false,
    },
    navigation: {
      nextEl: ".blog-slider-next",
      prevEl: ".blog-slider-prev",
    },
    pagination: {
      el: ".blog-pagination",
      clickable: true,
    },
    breakpoints: {
      280: {
        slidesPerView: 1,
      },
      386: {
        slidesPerView: 1,
      },
      576: {
        slidesPerView: 1,
      },
      768: {
        slidesPerView: 2,
      },
      992: {
        slidesPerView: 3,
        spaceBetween: 15,
      },
      1200: {
        slidesPerView: 3,
      },
      1400: {
        slidesPerView: 3,
      },
    },
  });
  // Home7 Activity tab Slider
  var swiper = new Swiper(".home7-activity-tab-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 20,
    autoplay: {
      delay: 2500, // Autoplay duration in milliseconds
      pauseOnMouseEnter: true,
      disableOnInteraction: false,
    },
    navigation: {
      nextEl: ".guide-slider-next",
      prevEl: ".guide-slider-prev",
    },
    pagination: {
      el: ".swiper-pagination1",
      clickable: true,
    },
    breakpoints: {
      280: {
        slidesPerView: 1,
      },
      350: {
        slidesPerView: 2,
        spaceBetween: 15,
      },
      576: {
        slidesPerView: 3,
      },
      768: {
        slidesPerView: 4,
      },
      992: {
        slidesPerView: 4,
        spaceBetween: 15,
      },
      1200: {
        slidesPerView: 4,
        spaceBetween: 15,
      },
      1400: {
        slidesPerView: 4,
      },
    },
  });
  // Home8 Package Slider
  var swiper = new Swiper(".home8-visa-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 24,
    autoplay: {
      delay: 2500, // Autoplay duration in milliseconds
      disableOnInteraction: false,
    },
    pagination: {
      el: ".swiper-pagination2",
      clickable: true,
    },
    breakpoints: {
      280: {
        slidesPerView: 1,
      },
      386: {
        slidesPerView: 1,
      },
      576: {
        slidesPerView: 1,
      },
      768: {
        slidesPerView: 2,
      },
      992: {
        slidesPerView: 3,
        spaceBetween: 15,
      },
      1200: {
        slidesPerView: 3,
      },
      1400: {
        slidesPerView: 3,
      },
    },
  });
  // Home8 Testimonial Slider
  var swiper = new Swiper(".home8-testimonial-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 24,
    autoplay: {
      delay: 2500, // Autoplay duration in milliseconds
      disableOnInteraction: false,
      pauseOnMouseEnter: true,
    },
    navigation: {
      nextEl: ".testimonial-slider-next",
      prevEl: ".testimonial-slider-prev",
    },
    breakpoints: {
      280: {
        slidesPerView: 1,
      },
      386: {
        slidesPerView: 1,
      },
      576: {
        slidesPerView: 1,
      },
      768: {
        slidesPerView: 2,
        spaceBetween: 15,
      },
      992: {
        slidesPerView: 2,
        spaceBetween: 15,
      },
      1200: {
        slidesPerView: 2,
        spaceBetween: 15,
      },
      1400: {
        slidesPerView: 2,
      },
    },
    on: {
      init: () => {
        setTimeout(equalizeSwiperSlideHeights, 100); // wait for DOM render
      },
      slideChangeTransitionEnd: () => {
        setTimeout(equalizeSwiperSlideHeights, 100);
      },
      resize: () => {
        setTimeout(equalizeSwiperSlideHeights, 100);
      },
    },
  });
  // location Slider
  var swiper = new Swiper(".package-dt-location-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 24,
    autoplay: {
      delay: 2500, // Autoplay duration in milliseconds
      pauseOnMouseEnter: true,
      disableOnInteraction: false,
    },
    navigation: {
      nextEl: ".location-slider-next",
      prevEl: ".location-slider-prev",
    },
    pagination: {
      el: ".swiper-pagination1",
      clickable: true,
    },
    breakpoints: {
      280: {
        slidesPerView: 1,
      },
      386: {
        slidesPerView: 1,
      },
      576: {
        slidesPerView: 2,
      },
      768: {
        slidesPerView: 3,
      },
      992: {
        slidesPerView: 3,
        spaceBetween: 15,
      },
      1200: {
        slidesPerView: 3,
        spaceBetween: 15,
      },
      1400: {
        slidesPerView: 3,
      },
    },
  });
  // Destination details gallery Slider
  var swiper = new Swiper(".destination-details-gallery-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 10,
    autoplay: {
      delay: 2500, // Autoplay duration in milliseconds
      pauseOnMouseEnter: true,
      disableOnInteraction: false,
    },
    navigation: {
      nextEl: ".destination-dt-gallery-slider-next",
      prevEl: ".destination-dt-gallery-slider-prev",
    },
    breakpoints: {
      280: {
        slidesPerView: 1,
      },
      386: {
        slidesPerView: 1,
      },
      576: {
        slidesPerView: 1,
      },
      768: {
        slidesPerView: 2,
      },
      992: {
        slidesPerView: 3,
      },
      1200: {
        slidesPerView: 3,
      },
      1400: {
        slidesPerView: 3,
      },
    },
  });
  // Destination Details location Slider
  var swiper = new Swiper(".destination-dt-location-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 24,
    autoplay: {
      delay: 2500, // Autoplay duration in milliseconds
      pauseOnMouseEnter: true,
      disableOnInteraction: false,
    },
    navigation: {
      nextEl: ".location-slider-next",
      prevEl: ".location-slider-prev",
    },
    breakpoints: {
      280: {
        slidesPerView: 1,
      },
      386: {
        slidesPerView: 1,
      },
      576: {
        slidesPerView: 3,
        spaceBetween: 15,
      },
      768: {
        slidesPerView: 4,
      },
      992: {
        slidesPerView: 5,
        spaceBetween: 15,
      },
      1200: {
        slidesPerView: 6,
        spaceBetween: 15,
      },
      1400: {
        slidesPerView: 6,
      },
    },
  });
  // Destination Details Customer gallery Slider
  var swiper = new Swiper(".destionation-dt-customer-gallery-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 30,
    autoplay: {
      delay: 2500, // Autoplay duration in milliseconds
      pauseOnMouseEnter: true,
      disableOnInteraction: false,
    },
    breakpoints: {
      280: {
        slidesPerView: 1,
      },
      386: {
        slidesPerView: 1,
      },
      576: {
        slidesPerView: 2,
        spaceBetween: 15,
      },
      768: {
        slidesPerView: 3,
      },
      992: {
        slidesPerView: 4,
        spaceBetween: 15,
      },
      1200: {
        slidesPerView: 5,
        spaceBetween: 15,
      },
      1400: {
        slidesPerView: 5,
        spaceBetween: 20,
      },
      1600: {
        slidesPerView: 5,
        spaceBetween: 24,
      },
    },
  });
  // Destination Details Customer gallery Slider
  var swiper = new Swiper(".destionation4-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 30,
    autoplay: {
      delay: 2500, // Autoplay duration in milliseconds
      pauseOnMouseEnter: true,
      disableOnInteraction: false,
    },
    pagination: {
      el: ".swiper-pagination1",
      clickable: true,
    },
    breakpoints: {
      280: {
        slidesPerView: 1,
      },
      386: {
        slidesPerView: 1,
      },
      576: {
        slidesPerView: 2,
        spaceBetween: 15,
      },
      768: {
        slidesPerView: 2,
      },
      992: {
        slidesPerView: 3,
        spaceBetween: 15,
      },
      1200: {
        slidesPerView: 3,
        spaceBetween: 15,
      },
      1400: {
        slidesPerView: 3,
        spaceBetween: 24,
      },
    },
  });
  // Recent Post Slider
  var swiper = new Swiper(".related-product-slider", {
    speed: 1500,
    spaceBetween: 24,
    autoplay: {
      delay: 2000, // Autoplay duration in milliseconds
      disableOnInteraction: false,
      pauseOnMouseEnter: true,
    },
    navigation: {
      nextEl: ".related-product-slider-next",
      prevEl: ".related-product-slider-prev",
    },

    breakpoints: {
      280: {
        slidesPerView: 1,
      },
      420: {
        slidesPerView: 1,
        spaceBetween: 15,
      },
      576: {
        slidesPerView: 2,
        spaceBetween: 15,
      },
      768: {
        slidesPerView: 2,
      },
      992: {
        slidesPerView: 3,
      },
      1200: {
        slidesPerView: 4,
      },
      1400: {
        slidesPerView: 4,
      },
    },
  });
  // About Page Journey Slider
  var swiper = new Swiper(".about-page-journey-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 24,
    autoplay: {
      delay: 2500, // Autoplay duration in milliseconds
      pauseOnMouseEnter: true,
      disableOnInteraction: false,
    },
    breakpoints: {
      280: {
        slidesPerView: 1,
      },
      350: {
        slidesPerView: 2,
        spaceBetween: 10,
      },
      576: {
        slidesPerView: 3,
      },
      768: {
        slidesPerView: 4,
        spaceBetween: 10,
      },
      992: {
        slidesPerView: 5,
        spaceBetween: 15,
      },
      1200: {
        slidesPerView: 6,
        spaceBetween: 10,
      },
      1400: {
        slidesPerView: 6,
      },
    },
  });
  // Visa Dt Visa List Slider
  var swiper = new Swiper(".visa-dt-visa-list-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 24,
    autoplay: {
      delay: 2500, // Autoplay duration in milliseconds
      pauseOnMouseEnter: true,
      disableOnInteraction: false,
    },
    breakpoints: {
      280: {
        slidesPerView: 1,
      },
      350: {
        slidesPerView: 2,
        spaceBetween: 10,
      },
      576: {
        slidesPerView: 3,
      },
      768: {
        slidesPerView: 4,
        spaceBetween: 15,
      },
      992: {
        slidesPerView: 5,
        spaceBetween: 15,
      },
      1200: {
        slidesPerView: 6,
        spaceBetween: 15,
      },
      1400: {
        slidesPerView: 6,
      },
    },
  });
  // Hotel Details Gallery Slider
  var swiper = new Swiper(".hotel-dt-gallery-slider", {
    slidesPerView: 1,
    speed: 1500,
    spaceBetween: 24,
    autoplay: {
      delay: 2500,
      disableOnInteraction: false,
    },
    effect: "fade",
    fadeEffect: {
      crossFade: true,
    },
    navigation: {
      nextEl: ".hotel-dt-gallery-slider-next",
      prevEl: ".hotel-dt-gallery-slider-prev",
    },
  });

  function equalizeSwiperSlideHeights() {
    const slides = document.querySelectorAll(
      ".home8-testimonial-slider .swiper-slide"
    );

    if (!slides.length) return;

    // Reset all heights
    slides.forEach((slide) => {
      slide.style.height = "auto";
    });

    // Group slides by row using their top position
    const rows = [];
    let currentRow = [];
    let lastTop = null;

    slides.forEach((slide) => {
      const top = slide.getBoundingClientRect().top;

      if (lastTop === null || Math.abs(top - lastTop) < 5) {
        currentRow.push(slide);
      } else {
        rows.push(currentRow);
        currentRow = [slide];
      }
      lastTop = top;
    });

    if (currentRow.length) {
      rows.push(currentRow);
    }

    // Set max height for each row
    rows.forEach((row) => {
      let maxHeight = 0;
      row.forEach((slide) => {
        maxHeight = Math.max(maxHeight, slide.offsetHeight);
      });
      row.forEach((slide) => {
        slide.style.height = maxHeight + "px";
      });
    });
  }

  //wow js
  jQuery(window).on("load", function () {
    new WOW().init();
    window.wow = new WOW({
      boxClass: "wow",
      animateClass: "animated",
      offset: 0,
      mobile: true,
      live: true,
      offset: 80,
    });
    window.wow.init();
  });

  // niceSelect
  if ($("select").length) {
    $("select").niceSelect();
  }

  // Language Btn
  $(".language-btn, .search-btn").on("click", function (e) {
    let parent = $(this).parent();
    parent.find(".language-list, .search-input").toggleClass("active");
    e.stopPropagation();
  });
  $(document).on("click", function (e) {
    if (
      !$(e.target).closest(".language-btn, .search-btn, .search-input").length
    ) {
      $(".language-list, .search-input").removeClass("active");
    }
  });
  $(".search-close").on("click", function (e) {
    $(".search-input").removeClass("active");
  });
  // Contact DropDown Btn
  $(".contact-dropdown-btn").on("click", function (e) {
    let parent = $(this).parent();
    parent.find(".contact-list").toggleClass("active");
    e.stopPropagation();
  });
  $(document).on("click", function (e) {
    if (!$(e.target).closest(".contact-dropdown-btn").length) {
      $(".contact-list").removeClass("active");
    }
  });

  // Handle click on the input item
  $(".custom-select-dropdown").on("click", function () {
    $(".custom-select-wrap").toggleClass("active");
  });

  $(".single-search-box, .single-field").each(function () {
    var $box = $(this);
    var $dropdown = $box.find(".custom-select-dropdown");
    var $input = $dropdown.find("input");
    var $wrap = $box.find(".custom-select-wrap");
    var $searchInput = $wrap.find(".custom-select-search-area input");

    // Toggle dropdown on click
    $dropdown.on("click", function (e) {
      e.stopPropagation();
      $(".custom-select-wrap").removeClass("active"); // Close others
      $wrap.toggleClass("active");
    });

    // Handle option click
    $wrap.find(".option-list-destination li").on("click", function () {
      var country = $(this).find(".destination h6").text();
      var destination = $(this).find(".destination span").text();
      const countryDestinationHtml = `<div class="destination"><h6>${country}</h6> <span>${destination}</span></div>`;
      $box.find(".input-field-value").empty().html(countryDestinationHtml); // ✅ FIXED
      $input.val(country + destination);
      $wrap.removeClass("active");
    });

    $wrap.find(".option-list li").on("click", function () {
      var value = $(this).find("h6").text();
      $input.val(value);
      $wrap.removeClass("active");
    });

    // Search filter
    $searchInput.on("input", function () {
      var searchText = $(this).val().toLowerCase();
      $wrap
        .find(".option-list-destination li, .option-list li")
        .each(function () {
          var destinationText = $(this)
            .find(".destination h6, h6")
            .text()
            .toLowerCase();
          $(this).toggle(destinationText.includes(searchText));
        });
    });

    // Close dropdown on click outside
    $(document).on("click", function (event) {
      if (!$(event.target).closest($box).length) {
        $wrap.removeClass("active");
      }
    });
  });

  // calender
  $(function () {
    const today = moment();
    const checkOutDefault = moment().add(3, "days");
    const tomorrow = moment().add(1, "days");

    // ----------- General Calendar: inOut (Per Dropdown) ------------
    $('input[name="inOut"]').each(function () {
      const $input = $(this);
      const $display = $input
        .closest(".custom-select-dropdown")
        .find(".selected-date");

      $input.daterangepicker(
        {
          singleDatePicker: true,
          startDate: today,
          minYear: 2025,
          maxYear: 2026,
          locale: {
            format: "DD-MMM-YYYY",
          },
        },
        function (start) {
          const formattedDayMonth = start.format("D MMMM");
          const formattedDayYear = start.format("dddd YYYY");
          const formattedDate = `<div class="selected-date"><h6>${formattedDayMonth}</h6><span>${formattedDayYear}</span></div>`;
          $display.html(formattedDate);
        }
      );

      // Initialize default
      $display.html(
        `<div class="selected-date"><h6>${today.format(
          "D MMMM"
        )}</h6><span>${today.format("dddd YYYY")}</span></div>`
      );
    });
    $('input[name="inOut2"]').each(function () {
      const $input = $(this);

      $input.daterangepicker(
        {
          singleDatePicker: true,
          showDropdowns: true,
          startDate: "01-Jan-2005",
          minYear: 1980,
          maxYear: 2020,
          locale: {
            format: "DD-MMM-YYYY",
          },
        },
        function (start) {
          var years = moment().diff(start, "years");
        }
      );
    });

    // ----------- Hotel Check-In (Scoped) ------------
    $("input.hotel-checkin").each(function () {
      const $input = $(this);
      const $display = $input
        .closest(".custom-select-dropdown, .hotel-box, .date-box")
        .find(".hotel-selected-date-checkin");
      const $display2 = jQuery(document).find(".hotel-selected-date-checkout");

      $input.daterangepicker(
        {
          opens: "center",
          startDate: today,
          endDate: checkOutDefault,
          minYear: 2025,
          maxYear: 2026,
          locale: {
            format: "DD-MMM",
          },
        },
        function (start, end) {
          const formatted = start.format("D MMMM");
          $display.html(`<h6>${formatted}</h6><span>Check-In</span>`);

          const formattedCheckOut = end.format("D MMMM");
          $display2.html(`<h6>${formattedCheckOut}</h6><span>Check-Out</span>`);
        }
      );
      $("#hotel-details-calendar").toggleClass("active");
      // Default display before selection
      $display.html(`<h6>${today.format("D MMMM")}</h6><span>Check-In</span>`);
      $display2.html(
        `<h6>${checkOutDefault.format("D MMMM")}</h6><span>Check-Out</span>`
      );
    });

    // hotel details checkout

    $(".hotel-selected-date-checkin").html(
      `<h6>${tomorrow.format("D MMMM")}</h6><span>Check-In</span>`
    );
    $(".hotel-selected-date-checkout").html(
      `<h6>${checkOutDefault.format("D MMMM")}</h6><span>Check-Out</span>`
    );
    $(
      ".hotel-details-wrapper .booking-area .date-field, .hotel-details-wrapper .booking-area .date-field .custom-select-dropdown"
    ).click(function () {
      $("#hotel-details-calendar").toggleClass("active");
    });

    $("body").on("click", "#hotel-calendar-check", function (e) {
      e.preventDefault();
      $("#hotel-details-calendar").removeClass("active");
    });

    $("body").on("click", "#tour-calendar-check", function (e) {
      e.preventDefault();
      $("#tour-booking-calendar").removeClass("active");
    });
    

    // Tour details
    $(".booking-modal .selected-date").html(
      `<h6>${moment(today).format("D MMMM")}</h6><span>Booking Date</span>`
    );
    $(
      ".booking-modal .modal-content .date-field,.booking-modal .modal-content .date-field .custom-select-dropdown,.booking-modal .selected-date"
    ).click(function () {
      $("#tour-booking-calendar").toggleClass("active");
    });
    $(document).click(function (e) {
      if (!$(e.target).closest("#tour-booking-calendar, .date-field, .custom-select-dropdown, .selected-date").length) {
        $("#tour-booking-calendar").removeClass("active");
      }
    });
  });

  //Quantity Increment Guest
  function updateGuestSummary() {
    let totalAdults = 0;
    let totalChildren = 0;

    $('input[name="adult_quantity"]').each(function () {
      totalAdults += parseInt($(this).val(), 10) || 0;
    });

    $('input[name="child_quantity"]').each(function () {
      totalChildren += parseInt($(this).val(), 10) || 0;
    });

    $("#adult-qty").text(totalAdults);
    $("#child-qty").text(totalChildren);
  }

  function updateRoomTitles() {
    $(".room-list .single-room").each(function (index) {
      $(this)
        .find(".room-title h6")
        .text(`Room-${index + 1}`);
    });
  }

  function updateRoomSummary() {
    const roomCount = $(".room-list .single-room").length;
    $(".custom-select-dropdown span strong").text(roomCount);

    if (roomCount <= 1) {
      $(".room-delete-btn").hide();
    } else {
      $(".room-delete-btn").show();
    }
  }

  function createNewRoom() {
    const $lastRoom = $(".room-list .single-room").last();
    const $newRoom = $lastRoom.clone();

    // Reset values
    $newRoom.find('input[name="adult_quantity"]').val(1);
    $newRoom.find('input[name="child_quantity"]').val(0);
    $newRoom.find(".guest-count").hide();
    $newRoom.removeClass("active-room");

    return $newRoom;
  }

  // Add new room
  const maxRooms = 5;

  $(".add-btn").on("click", function () {
    const roomCount = $(".room-list .single-room").length;

    if (roomCount >= maxRooms) {
      $(this).prop("disabled", true).addClass("disabled");
      return;
    }

    const $newRoom = createNewRoom();

    // Remove active state from others and hide
    $(".room-list .single-room")
      .removeClass("active-room")
      .find(".guest-count")
      .slideUp(0);

    // Append the new room
    $(".room-list").append($newRoom);

    // Show the new one
    $newRoom.addClass("active-room").find(".guest-count").slideDown(200);

    updateRoomTitles();
    updateRoomSummary();
    updateGuestSummary();

    // Disable button if max reached
    if ($(".room-list .single-room").length >= maxRooms) {
      $(this).prop("disabled", true).addClass("disabled");
    }
  });

  // Event delegation for plus/minus buttons
  $(document).on(
    "click",
    ".guest-quantity__plus, .guest-quantity__minus",
    function (e) {
      e.preventDefault();

      const $btn = $(this);
      const $input = $btn.siblings(".quantity__input");
      const type = $btn.data("type");
      let value = parseInt($input.val(), 10) || 0;

      if ($btn.hasClass("guest-quantity__minus")) {
        if (
          (type === "adult" && value > 1) ||
          (type === "child" && value > 0)
        ) {
          $input.val(value - 1);
        }
      } else {
        $input.val(value + 1);
      }

      updateGuestSummary();
    }
  );

  // Delete room
  $(document).on("click", ".room-delete-btn", function (e) {
    e.stopPropagation();

    $(this).closest(".single-room").remove();

    updateRoomTitles();
    updateRoomSummary();
    updateGuestSummary();

    const $rooms = $(".room-list .single-room");

    if ($rooms.length > 0) {
      $rooms.removeClass("active-room").find(".guest-count").slideUp(0);
      $rooms.last().addClass("active-room").find(".guest-count").slideDown(200);
    }

    // Re-enable add button if room count is below max
    if ($rooms.length < maxRooms) {
      $(".add-btn").prop("disabled", false).removeClass("disabled");
    }
  });

  // Accordion toggle
  $(document).on("click", ".room-title", function () {
    const $thisRoom = $(this).closest(".single-room");

    if ($thisRoom.hasClass("active-room")) return;

    $(".single-room")
      .not($thisRoom)
      .removeClass("active-room")
      .find(".guest-count")
      .slideUp(200);
    $thisRoom.addClass("active-room").find(".guest-count").slideDown(200);
  });

  // Initial setup
  $(document).ready(function () {
    $(".room-list .single-room").first().find(".guest-count").show();
    $(".room-list .single-room").first().addClass("active-room");
    updateRoomSummary();
  });

  $(document).ready(function () {
    $(".filter-item-list .single-item").on("click", function () {
      var $clickedItem = $(this);
      var index = $clickedItem.index();

      // Add 'active' class to clicked item and remove from others
      $clickedItem.addClass("active").siblings().removeClass("active");

      // Show corresponding .filter-input by index
      $(".filter-input-wrap .filter-input")
        .removeClass("show")
        .eq(index)
        .addClass("show");
    });
  });

  // sidebar activities expand
  $(".checkbox-container span.expand").on("click", function () {
    $(".checkbox-container.two ul").height("auto");
    $(".expand").hide();
  });

  // sidebar filter clear button
  const clearBtn = document.getElementById("clear-filters");

  if (clearBtn) {
    clearBtn.addEventListener("click", function () {
      // 1. Uncheck all checkboxes
      document
        .querySelectorAll(".package-sidebar-area input[type='checkbox']")
        .forEach(function (checkbox) {
          checkbox.checked = false;
        });
    });
  }

  //Package details Accordion expand and collapse
  document.querySelectorAll(".tour-itinerary-area").forEach(function (area) {
    const expandBtn = area.querySelector(".expand-btn");
    if (expandBtn) {
      expandBtn.addEventListener("click", function (e) {
        e.preventDefault();
        const isExpanded = expandBtn.classList.contains("expanded");

        $(
          "#accordionTourPlan .accordion-collapse, #accordionTourPlan2 .accordion-collapse"
        ).each(function () {
          if (isExpanded) {
            $(this).collapse("hide");
          } else {
            $(this).collapse("show");
          }
        });

        expandBtn.textContent = isExpanded ? "Expand All +" : "Collapse All -";
        expandBtn.classList.toggle("expanded");
      });
    }
  });

  //Progress Bar
  document.querySelectorAll(".rating-progress-bar-wrap").forEach((wrap) => {
    const bar = wrap.querySelector(".rating-progress-bar-per");
    const percentDisplay = wrap.querySelector(".data-per");
    const target = parseFloat(bar.getAttribute("data-per")); // e.g., 90
    const duration = 1000; // in milliseconds

    let startTime = null;

    function animate(timestamp) {
      if (!startTime) startTime = timestamp;
      const elapsed = timestamp - startTime;
      const progress = Math.min(elapsed / duration, 1); // Ensure it doesn't go over 1

      const current = Math.floor(target * progress);
      bar.style.width = current + "%";
      percentDisplay.textContent = current + "%";

      if (progress < 1) {
        requestAnimationFrame(animate);
      }
    }

    requestAnimationFrame(animate);
  });

  // star-rating
  $(".rating-container .star-icon").each(function () {
    let self = $(this);

    self.on("mouseenter", function () {
      $(this).prevAll().addBack().addClass("hovered");
    });

    self.on("mouseleave", function () {
      $(".star-icon").removeClass("hovered");
    });

    self.on("click", function () {
      const rating = $(this).prevAll().length + 1;
      const parent = $(this).parent();
      parent.attr("data-rating", rating);

      parent.find(".star-icon").removeClass("selected");
      parent.find(".star-icon").each(function (index) {
        if (index < rating) {
          $(this).addClass("selected");
        }
      });
    });

    // On load or if data-rating already exists
    const parent = self.parent();
    const initRating = parseInt(parent.attr("data-rating")) || 0;
    parent.find(".star-icon").each(function (index) {
      if (index < initRating) {
        $(this).addClass("selected");
      }
    });
  });

  //list grid view
  $(".grid-view li").on("click", function () {
    // Get the class of the clicked li element
    var clickedClass = $(this).attr("class");
    // Extract the class name without "item-" prefix
    var className = clickedClass.replace("item-", "");
    // Add a new class to the target div and remove old classes
    var targetDiv = $(".list-grid-product-wrap");
    targetDiv
      .removeClass()
      .addClass("list-grid-product-wrap " + className + "-wrapper");
    // Remove the 'selected' class from siblings and add it to the clicked element
    $(this).siblings().removeClass("active");
    $(this).addClass("active");
  });

  //Package Details Checkbox
  jQuery(".sidebar-category-icon").on("click", function () {
    jQuery(this).toggleClass("active");
    jQuery(this).siblings(".sub-category").stop(true, true).slideToggle();
  });

  // Handle parent to child checkbox behavior
  jQuery('.sidebar-category-dropdown .containerss input[type="checkbox"]').on(
    "change",
    function () {
      const isChecked = $(this).prop("checked");
      const $container = $(this).closest(".containerss");
      const $checkboxes = $container
        .next("ul")
        .find('li input[type="checkbox"]');
      $checkboxes.prop("checked", isChecked);
    }
  );

  // Handle child to parent checkbox behavior
  jQuery('.sub-category li input[type="checkbox"]').on("change", function () {
    const $subCategory = $(this).closest("ul.sub-category");
    const $parentCheckbox = $subCategory
      .prevAll("label.containerss")
      .find('input[type="checkbox"]');

    if ($(this).prop("checked")) {
      $parentCheckbox.prop("checked", true);
    } else {
      // If no other siblings are checked, uncheck the parent
      const anyChecked =
        $subCategory.find('input[type="checkbox"]:checked').length > 0;
      $parentCheckbox.prop("checked", anyChecked);
    }
  });

  // Filter sidebar
  $(".filter-btn").on("click", function (e) {
    e.stopPropagation();

    $(".package-sidebar-area").toggleClass("slide");
    $(this).toggleClass("active"); // Toggle active class on filter button
  });

  $(document).on("click", function (e) {
    if (!$(e.target).closest(".package-sidebar-area, .filter-btn").length) {
      $(".package-sidebar-area").removeClass("slide");
      $(".filter-btn").removeClass("active"); // Remove active class when clicked outside
    }
  });

  //Cart Menu Quantity button toggle
  $(".qty-btn").on("click", function (e) {
    e.stopPropagation();
    // Toggle "active" class for the current quantity button and its related elements
    $(this).next(".quantity-area").toggleClass("active");

    // Remove "active" class from other quantity buttons and related elements
    $(".quantity-area")
      .not($(this).next(".quantity-area"))
      .removeClass("active");
  });
  $(document).on("click", function (e) {
    if (!$(e.target).closest(".quantity-area").length) {
      // Remove "active" class from all quantity buttons and related elements
      $(".quantity-area").removeClass("active");
    }
  });

  //Quantity Increment
  $(".quantity__minus").on("click", function (e) {
    e.preventDefault();
    var input = $(this).siblings(".quantity__input");
    var value = parseInt(input.val(), 10);
    if (value > 1) {
      value--;
    }
    input.val(value.toString().padStart(2, "0"));
  });
  $(".quantity__plus").on("click", function (e) {
    e.preventDefault();
    var input = $(this).siblings(".quantity__input");
    var value = parseInt(input.val(), 10);
    value++;
    input.val(value.toString().padStart(2, "0"));
  });

  // Payment Method
  $(function () {
    $(".choose-payment-method ul li").on("click", function () {
      $(".choose-payment-method ul li").removeClass("active"); // Remove active class from all list items
      if ($(this).hasClass("stripe")) {
        $("#StripePayment").show();
        $("#OfflinePayment").hide();
        $(this).addClass("active"); // Add active class to the clicked list item
      } else if ($(this).hasClass("paypal")) {
        $("#OfflinePayment").hide();
        $("#StripePayment").hide();
        $(this).addClass("active"); // Add active class to the clicked list item
      } else if ($(this).hasClass("offline")) {
        $("#OfflinePayment").show();
        $("#StripePayment").hide();
        $(this).addClass("active"); // Add active class to the clicked list item
      } else {
        $("#StripePayment").hide();
        $("#OfflinePayment").hide();
      }
    });
  });

  if ($("body").not(".is-mobile").hasClass("tt-magic-cursor")) {
    if ($(window).width() > 1024) {
      gsap.config({
        nullTargetWarn: false,
        trialWarn: false,
      });
      $(".magnetic-item").wrap('<div class="magnetic-wrap"></div>');

      if ($("a.magnetic-item").length) {
        $("a.magnetic-item").addClass("not-hide-cursor");
      }

      var $mouse = { x: 0, y: 0 }; // Cursor position
      var $pos = { x: 0, y: 0 }; // Cursor position
      var $ratio = 0.15; // delay follow cursor
      var $active = false;
      var $ball = $("#ball");

      var $ballWidth = 20; // Ball default width
      var $ballHeight = 20; // Ball default height
      var $ballOpacity = 0.5; // Ball default opacity
      var $ballBorderWidth = 2; // Ball default border width

      gsap.set($ball, {
        // scale from middle and style ball
        xPercent: -50,
        yPercent: -50,
        width: $ballWidth,
        height: $ballHeight,
        borderWidth: $ballBorderWidth,
        opacity: $ballOpacity,
      });

      document.addEventListener("mousemove", mouseMove);

      function mouseMove(e) {
        $mouse.x = e.clientX;
        $mouse.y = e.clientY;
      }

      gsap.ticker.add(updatePosition);

      function updatePosition() {
        if (!$active) {
          $pos.x += ($mouse.x - $pos.x) * $ratio;
          $pos.y += ($mouse.y - $pos.y) * $ratio;

          gsap.set($ball, { x: $pos.x, y: $pos.y });
        }
      }

      $(".magnetic-wrap").mousemove(function (e) {
        parallaxCursor(e, this, 2); // magnetic ball = low number is more attractive
        callParallax(e, this);
      });

      function callParallax(e, parent) {
        parallaxIt(e, parent, parent.querySelector(".magnetic-item"), 25); // magnetic area = higher number is more attractive
      }

      function parallaxIt(e, parent, target, movement) {
        var boundingRect = parent.getBoundingClientRect();
        var relX = e.clientX - boundingRect.left;
        var relY = e.clientY - boundingRect.top;

        gsap.to(target, {
          duration: 0.3,
          x: ((relX - boundingRect.width / 2) / boundingRect.width) * movement,
          y:
            ((relY - boundingRect.height / 2) / boundingRect.height) * movement,
          ease: Power2.easeOut,
        });
      }

      function parallaxCursor(e, parent, movement) {
        var rect = parent.getBoundingClientRect();
        var relX = e.clientX - rect.left;
        var relY = e.clientY - rect.top;
        $pos.x =
          rect.left + rect.width / 2 + (relX - rect.width / 2) / movement;
        $pos.y =
          rect.top + rect.height / 2 + (relY - rect.height / 2) / movement;
        gsap.to($ball, { duration: 0.3, x: $pos.x, y: $pos.y });
      }

      // Magic cursor behavior
      // ======================

      // Magnetic item hover.
      $(".magnetic-wrap")
        .on("mouseenter mouseover", function (e) {
          $ball.addClass("magnetic-active");
          gsap.to($ball, { duration: 0.3, width: 70, height: 70, opacity: 1 });
          $active = true;
        })
        .on("mouseleave", function (e) {
          $ball.removeClass("magnetic-active");
          gsap.to($ball, {
            duration: 0.3,
            width: $ballWidth,
            height: $ballHeight,
            opacity: $ballOpacity,
          });
          gsap.to(this.querySelector(".magnetic-item"), {
            duration: 0.3,
            x: 0,
            y: 0,
            clearProps: "all",
          });
          $active = false;
        });

      // Alternative cursor style on hover.
      $(
        ".cursor-alter, .tt-main-menu-list > li > a, .tt-main-menu-list > li > .tt-submenu-trigger > a"
      )
        .not(".magnetic-item") // omit from selection.
        .on("mouseenter", function () {
          gsap.to($ball, {
            duration: 0.3,
            borderWidth: 0,
            opacity: 0.2,
            backgroundColor: "#CCC",
            width: "90px",
            height: "90px",
          });
        })
        .on("mouseleave", function () {
          gsap.to($ball, {
            duration: 0.3,
            borderWidth: $ballBorderWidth,
            opacity: $ballOpacity,
            backgroundColor: "transparent",
            width: $ballWidth,
            height: $ballHeight,
            clearProps: "backgroundColor",
          });
        });

      // Cursor view on hover (data attribute "data-cursor="...").
      $("[data-cursor]").each(function () {
        $(this)
          .on("mouseenter", function () {
            $ball
              .addClass("ball-view")
              .append('<div class="ball-view-inner"></div>');
            $(".ball-view-inner").append($(this).attr("data-cursor"));
            gsap.to($ball, {
              duration: 0.3,
              yPercent: -75,
              width: 82,
              height: 82,
              opacity: 1,
              borderWidth: 0,
            });
            gsap.to(".ball-view-inner", {
              duration: 0.3,
              scale: 1,
              autoAlpha: 1,
            });
          })
          .on("mouseleave", function () {
            gsap.to($ball, {
              duration: 0.3,
              yPercent: -50,
              width: $ballWidth,
              height: $ballHeight,
              opacity: $ballOpacity,
              borderWidth: $ballBorderWidth,
            });
            $ball.removeClass("ball-view").find(".ball-view-inner").remove();
          });
        $(this).addClass("not-hide-cursor");
      });

      // Cursor drag on hover (class "cursor-drag"). For Swiper sliders.
      $(".swiper").each(function () {
        if ($(this).parent().attr("data-simulate-touch") === "true") {
          if ($(this).parent().hasClass("cursor-drag")) {
            $(this)
              .on("mouseenter", function () {
                $ball.append('<div class="ball-drag"></div>');
                gsap.to($ball, {
                  duration: 0.3,
                  width: 60,
                  height: 60,
                  opacity: 1,
                });
              })
              .on("mouseleave", function () {
                $ball.find(".ball-drag").remove();
                gsap.to($ball, {
                  duration: 0.3,
                  width: $ballWidth,
                  height: $ballHeight,
                  opacity: $ballOpacity,
                });
              });
            $(this).addClass("not-hide-cursor");

            // Ignore "data-cursor" on hover.
            $(this)
              .find("[data-cursor]")
              .on("mouseenter mouseover", function () {
                $ball.find(".ball-drag").remove();
                return false;
              })
              .on("mouseleave", function () {
                $ball.append('<div class="ball-drag"></div>');
                gsap.to($ball, {
                  duration: 0.3,
                  width: 60,
                  height: 60,
                  opacity: 1,
                });
              });
          }
        }
      });

      // Cursor drag on mouse down / click and hold effect (class "cursor-drag-mouse-down"). For Swiper sliders.
      $(".swiper").each(function () {
        if ($(this).parent().attr("data-simulate-touch") === "true") {
          if ($(this).parent().hasClass("cursor-drag-mouse-down")) {
            $(this)
              .on("mousedown pointerdown", function (e) {
                if (e.which === 1) {
                  // Affects the left mouse button only!
                  gsap.to($ball, {
                    duration: 0.2,
                    width: 60,
                    height: 60,
                    opacity: 1,
                  });
                  $ball.append('<div class="ball-drag"></div>');
                }
              })
              .on("mouseup pointerup", function () {
                $ball.find(".ball-drag").remove();
                if ($(this).find("[data-cursor]:hover").length) {
                } else {
                  gsap.to($ball, {
                    duration: 0.2,
                    width: $ballWidth,
                    height: $ballHeight,
                    opacity: $ballOpacity,
                  });
                }
              })
              .on("mouseleave", function () {
                $ball.find(".ball-drag").remove();
                gsap.to($ball, {
                  duration: 0.2,
                  width: $ballWidth,
                  height: $ballHeight,
                  opacity: $ballOpacity,
                });
              });

            // Ignore "data-cursor" on mousedown.
            $(this)
              .find("[data-cursor]")
              .on("mousedown pointerdown", function () {
                return false;
              });

            // Ignore "data-cursor" on hover.
            $(this)
              .find("[data-cursor]")
              .on("mouseenter mouseover", function () {
                $ball.find(".ball-drag").remove();
                return false;
              });
          }
        }
      });

      // Cursor close on hover.
      $(".cursor-close").each(function () {
        $(this).addClass("ball-close-enabled");
        $(this)
          .on("mouseenter", function () {
            $ball.addClass("ball-close-enabled");
            $ball.append('<div class="ball-close">Close</div>');
            gsap.to($ball, {
              duration: 0.3,
              yPercent: -75,
              width: 80,
              height: 80,
              opacity: 1,
            });
            gsap.from(".ball-close", { duration: 0.3, scale: 0, autoAlpha: 0 });
          })
          .on("mouseleave click", function () {
            $ball.removeClass("ball-close-enabled");
            gsap.to($ball, {
              duration: 0.3,
              yPercent: -50,
              width: $ballWidth,
              height: $ballHeight,
              opacity: $ballOpacity,
            });
            $ball.find(".ball-close").remove();
          });

        // Hover on "cursor-close" inner elements.
        $(
          ".cursor-close a, .cursor-close button, .cursor-close .tt-btn, .cursor-close .hide-cursor"
        )
          .not(".not-hide-cursor") // omit from selection (class "not-hide-cursor" is for global use).
          .on("mouseenter", function () {
            $ball.removeClass("ball-close-enabled");
          })
          .on("mouseleave", function () {
            $ball.addClass("ball-close-enabled");
          });
      });

      // ================================================================
      // Scroll between anchors
      // ================================================================

      $('a[href^="#"]')
        .not('[href$="#"]') // omit from selection
        .not('[href$="#0"]') // omit from selection
        .on("click", function () {
          var target = this.hash;

          // If fixed header position enabled.
          if ($("#tt-header").hasClass("tt-header-fixed")) {
            var $offset = $("#tt-header").height();
          } else {
            var $offset = 0;
          }

          // You can use data attribute (for example: data-offset="100") to set top offset in HTML markup if needed.
          if ($(this).data("offset") != undefined)
            $offset = $(this).data("offset");

          return false;
        });

      // Show/hide magic cursor
      // =======================

      // Hide on hover.
      $(
        "a, button, .tt-btn, .tt-form-control, .tt-form-radio, .tt-form-check, .hide-cursor"
      ) // class "hide-cursor" is for global use.
        .not(".not-hide-cursor") // omit from selection (class "not-hide-cursor" is for global use).
        .not(".cursor-alter") // omit from selection
        .not(".tt-main-menu-list > li > a") // omit from selection
        .not(".tt-main-menu-list > li > .tt-submenu-trigger > a") // omit from selection
        .on("mouseenter", function () {
          gsap.to($ball, { duration: 0.3, scale: 0, opacity: 0 });
        })
        .on("mouseleave", function () {
          gsap.to($ball, { duration: 0.3, scale: 1, opacity: $ballOpacity });
        });

      // Hide on click.
      $("a")
        .not('[target="_blank"]') // omit from selection.
        .not('[href^="#"]') // omit from selection.
        .not('[href^="mailto"]') // omit from selection.
        .not('[href^="tel"]') // omit from selection.
        .not(".lg-trigger") // omit from selection.
        .not(".video-player") // omit from selection.
        .not(".tt-btn-disabled") // omit from selection.
        .on("click", function () {
          gsap.to($ball, { duration: 0.3, scale: 1.3, autoAlpha: 0 });
        });

      // Show/hide on document leave/enter.
      $(document)
        .on("mouseleave", function () {
          gsap.to("#magic-cursor", { duration: 0.3, autoAlpha: 0 });
        })
        .on("mouseenter", function () {
          gsap.to("#magic-cursor", { duration: 0.3, autoAlpha: 1 });
        });

      // Show as the mouse moves.
      $(document).mousemove(function () {
        gsap.to("#magic-cursor", { duration: 0.3, autoAlpha: 1 });
      });
    }
  }

  // Back To Top
  $(document).ready(function () {
    "use strict";
    var progressPath = document.querySelector(
      ".progress-wrap .progress-circle path"
    );
    var pathLength = progressPath.getTotalLength();
    progressPath.style.transition = progressPath.style.WebkitTransition =
      "none";
    progressPath.style.strokeDasharray = pathLength + " " + pathLength;
    progressPath.style.strokeDashoffset = pathLength;
    progressPath.getBoundingClientRect();
    progressPath.style.transition = progressPath.style.WebkitTransition =
      "stroke-dashoffset 10ms linear";
    var updateProgress = function () {
      var scroll = $(window).scrollTop();
      var height = $(document).height() - $(window).height();
      var progress = pathLength - (scroll * pathLength) / height;
      progressPath.style.strokeDashoffset = progress;
    };
    updateProgress();
    $(window).scroll(updateProgress);
    var offset = 50;
    var duration = 550;
    jQuery(window).on("scroll", function () {
      if (jQuery(this).scrollTop() > offset) {
        jQuery(".progress-wrap").addClass("active-progress");
      } else {
        jQuery(".progress-wrap").removeClass("active-progress");
      }
    });
    jQuery(".progress-wrap").on("click", function () {
      window.scrollTo({ top: 0, behavior: "smooth" });
    });
  });

  //Scroll Down Button
  const scrollBtn = document.querySelector("#scroll-btn");
  if (scrollBtn) {
    scrollBtn.addEventListener("click", function (e) {
      e.preventDefault();
      document.querySelector("#scroll-section").scrollIntoView({
        behavior: "smooth",
      });
    });
  }

  // Select all visa items, visa wrappers, and visa info wraps
  const visaItems = document.querySelectorAll(".single-visa");
  const visaWrappers = document.querySelectorAll(".visa-details-wrapper");
  const visaInfos = document.querySelectorAll(".visa-info-wrap");

  visaItems.forEach((item, index) => {
    item.addEventListener("click", () => {
      // Remove 'active' from all visa items
      visaItems.forEach((i) => i.classList.remove("active"));
      // Remove 'active' from all wrappers
      visaWrappers.forEach((w) => w.classList.remove("active"));
      // Remove 'active' from all info wraps
      visaInfos.forEach((info) => info.classList.remove("active"));

      // Add 'active' to clicked visa
      item.classList.add("active");
      // Add 'active' to corresponding wrapper
      visaWrappers[index].classList.add("active");
      // Add 'active' to corresponding info wrap
      visaInfos[index].classList.add("active");
    });
  });

  // For Drop Area
  if (
    typeof Dropzone !== "undefined" &&
    document.querySelector(".dropzone-1")
  ) {
    new Dropzone(".dropzone-1", {
      url: "#",
      method: "get",
      dictDefaultMessage: ``,
      previewTemplate: document.querySelector(".my-template").innerHTML,
    });
  }

  // Points data
  const points = [
    {
      coords: [46.532388, 2.438788],
      name: "<h6>Vesdun</h6>",
      days: ["1", "2", "3"],
    },
    {
      coords: [46.504856, 2.713969],
      name: "<h6>Hérisson</h6>",
      days: ["4", "6"],
    },
    {
      coords: [46.441871, 2.423273],
      name: "<h6>Chambérat</h6>",
      days: ["5", "7"],
    },
    {
      coords: [46.490241, 2.527793],
      name: "<h6>Chazemais</h6>",
      days: ["1", "3"],
    },
    {
      coords: [46.532388, 2.438788],
      name: "<h6>Vesdun</h6>",
      days: ["1", "2", "3"],
    },
  ];

  // Reusable function
  function initLeafletMap(mapId, points) {
    const mapContainer = document.getElementById(mapId);
    if (!mapContainer) return null;

    const map = L.map(mapId).setView([80, -4.7], 8);

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      attribution: "© OpenStreetMap contributors",
    }).addTo(map);

    // Polyline
    const polyline = L.polyline(
      points.map((p) => p.coords),
      { color: "#1781FE", weight: 1 }
    ).addTo(map);
    map.fitBounds(polyline.getBounds());

    // Custom icons
    const customIcon = L.icon({
      iconUrl: "assets/img/innerpages/icon/package-dt-map-icon.svg",
      iconSize: [16, 16],
      iconAnchor: [6, 6],
    });

    const animatedIcon = L.icon({
      iconUrl: "assets/img/innerpages/icon/package-dt-map-icon2.svg",
      iconSize: [12, 12],
      iconAnchor: [6, 6],
    });

    // Add clickable markers
    points.forEach((p) => {
      let daysHtml = "";
      p?.days?.forEach((item) => {
        daysHtml += `<span>${item}</span>`;
      });

      L.marker(p.coords, { icon: customIcon }).addTo(map).bindPopup(`
          ${p.name}
          <strong>Days </strong>
          ${daysHtml}`);
    });

    // Animated marker
    const marker = L.marker(points[0].coords, { icon: animatedIcon }).addTo(
      map
    );

    function interpolatePosition(p1, p2, t) {
      return [p1[0] + (p2[0] - p1[0]) * t, p1[1] + (p2[1] - p1[1]) * t];
    }

    let i = 0;
    let progress = 0;
    const speed = 0.002;

    function animate() {
      if (i >= points.length - 1) {
        i = 0;
        progress = 0;
      }

      const start = points[i].coords;
      const end = points[i + 1].coords;

      progress += speed;

      if (progress >= 1) {
        i++;
        progress = 0;
      }

      const pos = interpolatePosition(start, end, progress);
      marker.setLatLng(pos);

      requestAnimationFrame(animate);
    }

    animate();

    return map;
  }

  // ---- Usage ----

  // Normal page map
  initLeafletMap("map", points);

  // Modal map
  let modalMap;
  const modalEl = document.getElementById("mapViewModal");
  if (modalEl) {
    modalEl.addEventListener("shown.bs.modal", function () {
      if (!modalMap) {
        modalMap = initLeafletMap("mapModal", points); // init only once
      }
      modalMap.invalidateSize(); // fix sizing when modal is shown
    });
  }
})(jQuery);
