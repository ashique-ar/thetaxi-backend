$(function () {
  // Wait for document ready
  let smoother;
  
  function initSmoothScroll() {
    try {
      // Register GSAP plugins
      gsap.registerPlugin(ScrollTrigger, ScrollSmoother);
      
      // Disable default normalize scroll
      ScrollTrigger.normalizeScroll(false);
      
      // Clear any existing smoothers
      if (smoother) {
        smoother.kill();
      }
      
      // Create new smoother with error handling
      smoother = ScrollSmoother.create({
        smooth: 2,
        effects: true,
        normalizeScroll: false,
        ignoreMobileResize: true,
        smoothTouch: 0.1,
      });
      
      // Force refresh on complete load
      window.addEventListener('load', () => {
        setTimeout(() => {
          smoother.refresh();
        }, 200);
      });
      
    } catch (err) {
      console.warn('Smooth scroll init error:', err);
    }
  }
  
  // Initialize
  initSmoothScroll();
  
  // Reinit on significant events
  let resizeTimer;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
      initSmoothScroll();
    }, 250);
  });
});