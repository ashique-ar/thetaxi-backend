<div id="checkout-v2-overlays">
    <div class="modal fade" id="checkoutAddonModal" tabindex="-1" aria-labelledby="checkoutAddonModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable v2-modal-dialog">
            <div class="modal-content overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-modal">
                <div class="flex items-center justify-between gap-4 border-b border-slate-100 bg-slate-50/70 p-5 sm:p-6">
                    <div class="flex min-w-0 items-center gap-3.5"><span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-checkout-accent-border bg-checkout-accent-soft text-checkout-accent"><i class="bi bi-grid" aria-hidden="true"></i></span><div class="min-w-0"><h2 id="checkoutAddonModalLabel" class="text-lg font-bold leading-tight text-slate-900">Manage Booking Add-ons</h2><p class="checkout-addon-modal-vehicle mt-0.5 text-xs font-medium text-slate-500"></p></div></div>
                    <button type="button" data-bs-dismiss="modal" aria-label="Close dialog" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-200/60 hover:text-slate-700"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="modal-body bg-slate-50/50 p-5 sm:p-6"><div id="checkoutAddonModalStatus" class="v2-addon-status" role="alert" hidden></div><div id="checkoutAddonModalPanel" class="checkout-addon-panel" aria-live="polite"></div></div>
                <div class="flex items-center justify-end border-t border-slate-100 bg-white p-5"><button type="button" data-bs-dismiss="modal" class="rounded-xl bg-checkout-accent px-5 py-3 text-xs font-bold text-white hover:bg-checkout-accent-hover">Done</button></div>
            </div>
        </div>
    </div>
    <div class="modal fade" id="checkoutExtraKmModal" tabindex="-1" aria-labelledby="checkoutExtraKmModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable v2-modal-dialog v2-modal-dialog--distance">
            <div class="modal-content overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-modal">
                <div class="flex items-center justify-between gap-4 border-b border-slate-100 bg-white p-5 sm:p-6">
                    <div class="flex min-w-0 items-center gap-3"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-checkout-accent-soft text-checkout-accent"><i class="bi bi-speedometer2" aria-hidden="true"></i></span><div class="min-w-0"><h2 id="checkoutExtraKmModalLabel" class="text-lg font-bold text-slate-900">Extra Kilometers</h2><p class="checkout-extra-km-modal-vehicle mt-0.5 text-xs text-slate-500"></p></div></div>
                    <button type="button" data-bs-dismiss="modal" aria-label="Close dialog" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div id="checkoutExtraKmModalPanel" class="modal-body bg-slate-50/50 p-5 sm:p-6"></div>
                <div class="flex items-center justify-end border-t border-slate-100 bg-white p-5"><button type="button" data-bs-dismiss="modal" class="rounded-xl bg-checkout-accent px-5 py-3 text-xs font-bold text-white hover:bg-checkout-accent-hover">Done</button></div>
            </div>
        </div>
    </div>
</div>
