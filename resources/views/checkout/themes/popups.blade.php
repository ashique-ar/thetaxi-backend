@php $checkoutPopupTheme = in_array(get_active_theme(), ['theme-03', 'theme-04'], true) ? 'light' : 'dark'; @endphp
<div class="modal fade checkout-addon-modal checkout-popup checkout-popup--{{ $checkoutPopupTheme }}" id="checkoutAddonModal" tabindex="-1" aria-labelledby="checkoutAddonModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div class="checkout-popup__heading"><span class="checkout-popup__icon" aria-hidden="true"><i class="bi bi-grid"></i></span><div><h2 class="modal-title" id="checkoutAddonModalLabel">Manage Booking Add-ons</h2><small class="checkout-addon-modal-vehicle"></small></div></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body"><div class="checkout-addon-panel" id="checkoutAddonModalPanel"></div></div>
            <div class="modal-footer"><button type="button" class="checkout-addon-modal-done" data-bs-dismiss="modal">Done</button></div>
        </div>
    </div>
</div>
<div class="modal fade checkout-popup checkout-popup--{{ $checkoutPopupTheme }}" id="checkoutExtraKmModal" tabindex="-1" aria-labelledby="checkoutExtraKmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div class="checkout-popup__heading"><span class="checkout-popup__icon" aria-hidden="true"><i class="bi bi-speedometer2"></i></span><div><h2 class="modal-title" id="checkoutExtraKmModalLabel">Pre-purchase Extra Kilometers</h2><small class="checkout-extra-km-modal-vehicle"></small></div></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="checkoutExtraKmModalPanel"></div>
            <div class="modal-footer"><button type="button" class="checkout-addon-modal-done" data-bs-dismiss="modal">Done</button></div>
        </div>
    </div>
</div>
