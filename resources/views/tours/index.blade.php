@extends('layouts.app')

@section('title', 'Plan your tour')

@push('styles')
<style>
    .tour-page { --tour-ink:#172b4d; --tour-muted:#64748b; color:var(--tour-ink); }
    .tour-hero { background:linear-gradient(125deg,#123c55,#187b75); color:#fff; border-radius:1.25rem; padding:clamp(2rem,5vw,4rem); }
    .tour-card { border:1px solid #e2e8f0; border-radius:1rem; background:#fff; box-shadow:0 12px 35px rgba(15,23,42,.05); }
    .tour-step { border-radius:999px; padding:.35rem .75rem; background:#e8f4f2; color:#17675f; font-size:.8rem; font-weight:700; }
    .tour-option { border:1px solid #dbe3ec; border-radius:.8rem; padding:1rem; height:100%; }
    .tour-option:has(input:checked) { border-color:#187b75; box-shadow:0 0 0 2px rgba(24,123,117,.12); }
    .tour-muted { color:var(--tour-muted); }
    .tour-route-stop { border-left:2px solid #cbd5e1; margin-left:.55rem; padding:0 0 1.1rem 1.25rem; position:relative; }
    .tour-route-stop:before { content:""; position:absolute; left:-.42rem; top:.25rem; width:.7rem; height:.7rem; border-radius:50%; background:#187b75; }
    .tour-route-stop:last-child { border:0; padding-bottom:0; }
    .tour-mobile-summary { z-index:1030; }
    @media (max-width: 991.98px) { .tour-page { padding-bottom:6rem; } }
    [hidden] { display:none !important; }
</style>
@endpush

@section('content')
<main class="container tour-page py-4 py-lg-5">
    <section class="tour-hero mb-4 mb-lg-5">
        <span class="tour-step">Multi-day private tours</span>
        <h1 class="display-5 fw-bold mt-3 mb-2">Plan a smoother journey</h1>
        <p class="lead mb-0" style="max-width:48rem">Choose from our listed places and activities. We’ll compare your order with a shorter, practical route and estimate the transport price.</p>
    </section>

    <div id="tour-error" class="alert alert-danger" role="alert" hidden></div>
    <div id="tour-loading" class="tour-card p-4 text-center">Loading available tours…</div>

    <form id="tour-form" class="row g-4" hidden>
        <div class="col-lg-8">
            <section class="tour-card p-3 p-md-4 mb-4">
                <div class="d-flex align-items-start gap-3 mb-4">
                    <span class="tour-step">1</span>
                    <div><h2 class="h4 mb-1">Trip details</h2><p class="tour-muted mb-0">Tell us who is travelling and when.</p></div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6"><label for="tour-service" class="form-label">Tour service</label><select id="tour-service" class="form-select" required></select></div>
                    <div class="col-md-6"><label for="tour-package" class="form-label">Package <span class="tour-muted">(optional)</span></label><select id="tour-package" class="form-select"><option value="">Choose later</option></select></div>
                    <div class="col-12">
                        <fieldset class="border-0 p-0 m-0">
                            <legend class="form-label">Choose a vehicle class</legend>
                            <div id="tour-class-cards" class="row g-3" role="radiogroup" aria-label="Vehicle class"></div>
                        </fieldset>
                        <select id="tour-class" class="d-none" tabindex="-1" aria-hidden="true"></select>
                    </div>
                    <div class="col-md-6"><label for="tour-date" class="form-label">Start date</label><input id="tour-date" type="date" class="form-control" required></div>
                    <div class="col-md-6"><label for="tour-days" class="form-label">Number of days</label><input id="tour-days" type="number" class="form-control" min="1" max="60" value="2" required></div>
                    <div class="col-md-6"><label for="tour-time" class="form-label">Daily start time</label><input id="tour-time" type="time" class="form-control" value="09:00" required></div>
                    <div class="col-md-4"><label for="tour-adults" class="form-label">Adults</label><input id="tour-adults" type="number" class="form-control" min="0" max="100" value="2" required></div>
                    <div class="col-md-4"><label for="tour-children" class="form-label">Children</label><input id="tour-children" type="number" class="form-control" min="0" max="100" value="0" required><div class="form-text">If you need a child seat, choose a matching vehicle add-on in the shared checkout when this Tour offers one.</div></div>
                    <div class="col-md-4"><label for="tour-luggage" class="form-label">Luggage</label><input id="tour-luggage" type="number" class="form-control" min="0" max="100" value="0" required></div>
                    <div id="tour-custom-fields" class="row g-3"></div>
                    <div id="tour-ai-helper" class="col-12" hidden>
                        <div class="tour-option">
                            <h3 class="h6">Optional itinerary ideas</h3>
                            <p class="small tour-muted">Share a few interests to get an editable draft based on our published places. We send only your trip length, optional start date, interests, and the published tour catalogue. Suggestions do not confirm a route, price, availability, tickets, or reservations.</p>
                            <label for="tour-interests" class="form-label">What are you interested in? <span class="tour-muted">(optional)</span></label>
                            <textarea id="tour-interests" class="form-control" rows="2" maxlength="500" placeholder="For example: beaches, wildlife, history"></textarea>
                            <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
                                <button id="tour-ai-suggest" class="btn btn-outline-primary" type="button">Suggest itinerary ideas</button>
                                <span id="tour-ai-status" class="small tour-muted" role="status" aria-live="polite"></span>
                            </div>
                            <div id="tour-ai-preview" class="mt-3" hidden></div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="tour-card p-3 p-md-4 mb-4">
                <div class="d-flex align-items-start gap-3 mb-4">
                    <span class="tour-step">2</span>
                    <div><h2 class="h4 mb-1">Choose your places</h2><p class="tour-muted mb-0">Select a start and finish point, then add the places you want to visit. Activities are optional stops along the way.</p></div>
                </div>
                <div class="mb-3">
                    <label for="tour-start-search" class="form-label">Find a published start/finish place</label>
                    <input id="tour-start-search" type="search" class="form-control mb-2" autocomplete="off" placeholder="Search listed places">
                    <label for="tour-start" class="form-label">Start and finish at</label><select id="tour-start" class="form-select" required><option value="">Choose a listed place</option></select>
                </div>
                <div id="tour-day-settings" class="row g-3 mb-3"></div>
                <div class="mb-3"><label for="tour-destination-search" class="form-label">Search published places and activities</label><input id="tour-destination-search" type="search" class="form-control" autocomplete="off" placeholder="Type a place or activity name"></div>
                <div id="tour-destinations" class="row g-3"></div>
                <p class="small tour-muted mt-3 mb-0">Only locations published by our team are selectable here. Activities add a stop to your route when selected.</p>
            </section>

            <section class="tour-card p-3 p-md-4">
                <div class="d-flex align-items-start gap-3 mb-3">
                    <span class="tour-step">3</span>
                    <div><h2 class="h4 mb-1">Review your route and transport price</h2><p class="tour-muted mb-0">We calculate the route and configured transport price. When you continue, you’ll use the same checkout as taxi and rental bookings.</p></div>
                </div>
                <button id="tour-plan" class="btn btn-primary px-4" type="submit">Build route and quote</button>
                <span id="tour-working" class="ms-3 tour-muted" hidden>Checking route and price…</span>
                <div id="tour-results" class="mt-4" hidden></div>
            </section>

            <section class="tour-card p-3 p-md-4 mt-4" id="tour-contact-section">
                <div class="d-flex align-items-start gap-3 mb-3">
                    <span class="tour-step">4</span>
                    <div><h2 class="h4 mb-1">Tour contact</h2><p class="tour-muted mb-0">Add contact details so we can save this route and quote to your booking.</p></div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6"><label for="tour-first-name" class="form-label">First name</label><input id="tour-first-name" class="form-control" autocomplete="given-name" required></div>
                    <div class="col-md-6"><label for="tour-last-name" class="form-label">Last name</label><input id="tour-last-name" class="form-control" autocomplete="family-name"></div>
                    <div class="col-md-6"><label for="tour-email" class="form-label">Email</label><input id="tour-email" type="email" class="form-control" autocomplete="email" required></div>
                    <div class="col-md-6"><label for="tour-phone" class="form-label">Phone</label><input id="tour-phone" type="tel" class="form-control" autocomplete="tel" required></div>
                </div>
                <div class="mt-3">
                    <button id="tour-send-code" class="btn btn-link px-0" type="button">Returning customer? Send a verification code</button>
                    <div class="row g-2 align-items-end" id="tour-code-panel" hidden>
                        <div class="col-sm-6"><label for="tour-code" class="form-label">6-digit email code</label><input id="tour-code" class="form-control" inputmode="numeric" autocomplete="one-time-code" maxlength="6"></div>
                        <div class="col-sm-auto"><button id="tour-verify-code" class="btn btn-outline-primary" type="button">Verify and load details</button></div>
                    </div>
                    <div id="tour-code-status" class="small mt-2" role="status" aria-live="polite"></div>
                </div>
            </section>
        </div>
        <aside class="col-lg-4">
            <div class="tour-card p-3 p-md-4 sticky-lg-top" style="top:1rem">
                <section id="tour-total-summary" class="border-bottom mb-3 pb-3" aria-live="polite" hidden>
                    <h2 class="h6 mb-1">Estimated tour transport</h2>
                    <p id="tour-total-amount" class="h4 mb-1"></p>
                    <p id="tour-total-distance" class="small tour-muted mb-0"></p>
                </section>
                <h2 class="h5">Before you continue</h2>
                <ul class="small ps-3 mb-3">
                    <li class="mb-2">The suggested order tries to reduce backtracking while respecting activity opening times.</li>
                    <li class="mb-2">The displayed transport price is based on your selected route and current rates. Vehicle availability is checked as part of the shared checkout process.</li>
                    <li class="mb-2">After accepting the price, continue to the existing checkout to review the booking, enter checkout details, and choose an available payment or quotation option.</li>
                    <li>Activity visits are included in your route only. Tickets, entry fees and activity reservations are not included. Please arrange these separately. Activity booking will be introduced in a future release.</li>
                </ul>
                <p class="small tour-muted mb-0">This public website flow is separate from the customer portal. Your route, quote and booking request will be handled here.</p>
            </div>
        </aside>
    </form>
    <div id="tour-mobile-summary" class="tour-mobile-summary d-lg-none position-fixed bottom-0 start-0 end-0 bg-white border-top shadow p-2" hidden>
        <div class="container d-flex align-items-center justify-content-between gap-3">
            <div><span class="d-block small tour-muted">Estimated transport</span><strong id="tour-mobile-total"></strong></div>
            <button id="tour-mobile-review" class="btn btn-primary btn-sm" type="button">Review quote</button>
        </div>
    </div>
</main>
@endsection

@push('scripts')
<script>
(() => {
    const form = document.getElementById('tour-form');
    const loading = document.getElementById('tour-loading');
    const error = document.getElementById('tour-error');
    const serviceSelect = document.getElementById('tour-service');
    const classSelect = document.getElementById('tour-class');
    const packageSelect = document.getElementById('tour-package');
    const startSelect = document.getElementById('tour-start');
    const daySettings = document.getElementById('tour-day-settings');
    const destinationList = document.getElementById('tour-destinations');
    const results = document.getElementById('tour-results');
    const api = '/api/public/tours';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let services = [];
    let catalogue = null;
    let savedItinerary = null;
    let selectedTemplateDays = [];

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    const showError = (message) => { error.textContent = message; error.hidden = false; };
    const clearError = () => { error.textContent = ''; error.hidden = true; };
    function invalidateDisplayedQuote() {
        results.hidden = true;
        document.getElementById('tour-total-summary').hidden = true;
        document.getElementById('tour-mobile-summary').hidden = true;
    }
    function showQuoteSummary(quote) {
        if (quote.total_amount === null || quote.total_amount === undefined || !Number.isFinite(Number(quote.total_amount))) return;
        const formatted=`${quote.currency} ${Number(quote.total_amount).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}`;
        document.getElementById('tour-total-amount').textContent=formatted;
        document.getElementById('tour-total-distance').textContent=`${quote.distance_km} km estimated · Price held until ${new Date(quote.expires_at).toLocaleString()}`;
        document.getElementById('tour-mobile-total').textContent=formatted;
        document.getElementById('tour-total-summary').hidden=false;
        document.getElementById('tour-mobile-summary').hidden=false;
    }
    async function request(url, options = {}) {
        const response = await fetch(url, {credentials:'same-origin', ...options, headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf,...(options.headers || {})}});
        const body = await response.json().catch(() => ({}));
        if (!response.ok || body.success === false) {
            const messages = body.errors ? Object.values(body.errors).flat().join(' ') : body.message;
            throw new Error(messages || 'We could not load tour information. Please try again.');
        }
        return body.data;
    }
    function currentService() { return services.find(item => item.id === serviceSelect.value); }
    function fillServices() {
        serviceSelect.innerHTML = services.map(item => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.name)}</option>`).join('');
    }
    function fillOptions() {
        const service = currentService();
        classSelect.innerHTML = '<option value="">Choose a vehicle class</option>' + (service?.classes || []).map(item => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.name)}${item.max_passengers ? ` — up to ${escapeHtml(item.max_passengers)} passengers` : ''}</option>`).join('');
        packageSelect.innerHTML = '<option value="">Choose later</option>' + (service?.packages || []).map(item => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.name)}</option>`).join('');
        document.getElementById('tour-ai-helper').hidden = !service?.ai_itinerary_helper_enabled;
        document.getElementById('tour-ai-preview').hidden = true;
        document.getElementById('tour-ai-status').textContent = '';
        renderClassCards();
        renderCustomFields();
    }
    function renderCustomFields() {
        const fields=currentService()?.form_fields||[];
        document.getElementById('tour-custom-fields').innerHTML=fields.map(field=>{
            const id=`tour-custom-${field.name}`; const required=field.required?'required':''; const label=escapeHtml(field.label);
            let control='';
            if(field.type==='textarea') control=`<textarea class="form-control" id="${escapeHtml(id)}" data-custom-name="${escapeHtml(field.name)}" ${required} placeholder="${escapeHtml(field.placeholder||'')}"></textarea>`;
            else if(field.type==='select') control=`<select class="form-select" id="${escapeHtml(id)}" data-custom-name="${escapeHtml(field.name)}" ${required}><option value="">Choose</option>${(field.options||[]).map(option=>`<option value="${escapeHtml(option.value)}">${escapeHtml(option.label)}</option>`).join('')}</select>`;
            else if(field.type==='radio') control=`<div>${(field.options||[]).map((option,index)=>`<label class="form-check me-3"><input class="form-check-input" type="radio" id="${escapeHtml(id)}-${index}" name="${escapeHtml(id)}" data-custom-name="${escapeHtml(field.name)}" value="${escapeHtml(option.value)}" ${required&&index===0?'required':''}><span class="form-check-label">${escapeHtml(option.label)}</span></label>`).join('')}</div>`;
            else if(field.type==='checkbox') control=`<label class="form-check mt-2"><input class="form-check-input" type="checkbox" id="${escapeHtml(id)}" data-custom-name="${escapeHtml(field.name)}" ${required}><span class="form-check-label">${label}</span></label>`;
            else control=`<input class="form-control" type="${field.type==='number'?'number':'text'}" id="${escapeHtml(id)}" data-custom-name="${escapeHtml(field.name)}" ${required} placeholder="${escapeHtml(field.placeholder||'')}" ${field.type==='number'&&field.validation?.min!=null?`min="${escapeHtml(field.validation.min)}"`:''} ${field.type==='number'&&field.validation?.max!=null?`max="${escapeHtml(field.validation.max)}"`:''}>`;
            const caption=field.type==='radio'?`<div class="form-label">${label}${field.required?' *':''}</div>`:`<label class="form-label" for="${escapeHtml(id)}">${label}${field.required?' *':''}</label>`;
            return `<div class="col-md-6">${caption}${control}${field.hint?`<div class="form-text">${escapeHtml(field.hint)}</div>`:''}</div>`;
        }).join('');
    }
    function limitPackageClasses() {
        const chosen=(currentService()?.packages||[]).find(item=>item.id===packageSelect.value);
        const allowed=chosen?new Set([chosen.default_vehicle_class_id,...(chosen.allowed_upgrade_class_ids||[])].filter(Boolean)):null;
        [...classSelect.options].forEach(option=>{option.disabled=!!allowed&&option.value!==''&&!allowed.has(option.value);});
        if(allowed&&!allowed.has(classSelect.value))classSelect.value=chosen.default_vehicle_class_id||'';
        renderClassCards();
    }
    function renderClassCards() {
        const chosen=(currentService()?.packages||[]).find(item=>item.id===packageSelect.value);
        const allowed=chosen?new Set([chosen.default_vehicle_class_id,...(chosen.allowed_upgrade_class_ids||[])].filter(Boolean)):null;
        const partySize=Number(document.getElementById('tour-adults').value||0)+Number(document.getElementById('tour-children').value||0);
        const luggage=Number(document.getElementById('tour-luggage').value||0);
        const cards=document.getElementById('tour-class-cards');
        cards.innerHTML=(currentService()?.classes||[]).map((item,index)=>{
            const packageAllowed=!allowed||allowed.has(item.id);
            const capacityFits=partySize>0&&partySize<=Number(item.max_passengers)&&luggage<=Number(item.max_luggage);
            const disabled=!packageAllowed||!capacityFits;
            if(classSelect.value===item.id&&disabled)classSelect.value='';
            const selected=classSelect.value===item.id;
            const reason=!packageAllowed?'Not offered with this package':!capacityFits?'Does not fit the selected travellers or luggage':'';
            return `<div class="col-md-6"><label class="tour-option d-block h-100 ${disabled?'opacity-50':''}" for="tour-class-choice-${index}"><span class="d-flex gap-2 align-items-start"><input class="form-check-input mt-1 tour-class-choice" type="radio" name="tour_class_choice" id="tour-class-choice-${index}" value="${escapeHtml(item.id)}" ${selected?'checked':''} ${disabled?'disabled':''} required><span><strong>${escapeHtml(item.name)}</strong>${item.description?`<span class="d-block small tour-muted mt-1">${escapeHtml(item.description)}</span>`:''}<span class="d-block small mt-2">Up to ${escapeHtml(item.max_passengers)} travellers · ${escapeHtml(item.max_luggage)} luggage items</span>${item.is_inquiry_only?'<span class="d-block small text-warning mt-1">Availability confirmed by our team</span>':''}${reason?`<span class="d-block small tour-muted mt-1">${escapeHtml(reason)}</span>`:''}</span></span></label></div>`;
        }).join('');
        cards.querySelectorAll('.tour-class-choice').forEach(input=>input.addEventListener('change',()=>{
            if(input.checked){classSelect.value=input.value;results.hidden=true;}
        }));
    }
    function applyPackageTemplate() {
        const packageData=(currentService()?.packages||[]).find(item=>item.id===packageSelect.value);
        const days=packageData?.tour_itinerary_template?.days;
        selectedTemplateDays=Array.isArray(days)?days:[];
        if(!selectedTemplateDays.length)return;
        document.getElementById('tour-days').value=days.length;
        const count=days.length;
        document.querySelectorAll('.tour-place-day').forEach(select=>{
            const current=Number(select.value)||1;
            select.innerHTML=Array.from({length:count},(_,day)=>`<option value="${day+1}" ${current===day+1?'selected':''}>Day ${day+1}</option>`).join('');
        });
        const firstDay=[...days].sort((a,b)=>a.day_number-b.day_number)[0];
        startSelect.value=firstDay.start_destination_id;
        renderDaySettings();
        for(const day of days){
            const end=document.getElementById(`tour-day-end-${day.day_number}`);
            if(end)end.value=day.end_destination_id;
        }
        document.querySelectorAll('.tour-destination,.tour-activity').forEach(input=>{input.checked=false;});
        for(const day of days){
            for(const stop of day.stops||[]){
                const selector=stop.kind==='destination'?'.tour-destination':'.tour-activity';
                const input=[...document.querySelectorAll(selector)].find(item=>item.value===stop.catalogue_id);
                if(input){
                    input.checked=true;
                    if(stop.kind==='destination')delete input.dataset.activityOnlyParent;
                    if(stop.kind==='activity'){
                        const parent=document.getElementById(input.dataset.destinationCheckbox);
                        if(parent){parent.checked=true;if(!(day.stops||[]).some(item=>item.kind==='destination'&&item.catalogue_id===parent.value))parent.dataset.activityOnlyParent='true';}
                    }
                }
                const parentId=stop.kind==='activity'?document.querySelector(`.tour-activity[value="${CSS.escape(stop.catalogue_id)}"]`)?.dataset.parent:stop.catalogue_id;
                const destinationInput=parentId?[...document.querySelectorAll('.tour-destination')].find(item=>item.value===parentId):null;
                if(destinationInput){const cardIndex=destinationInput.id.replace('tour-destination-','');const daySelect=document.getElementById(`tour-place-day-${cardIndex}`);if(daySelect)daySelect.value=day.day_number;}
            }
        }
        limitPackageClasses();
        results.hidden=true;
    }
    function renderDaySettings() {
        if (!catalogue) return;
        const destinations = catalogue.destinations || [];
        const days = Number(document.getElementById('tour-days').value) || 1;
        const priorEnds = new Map([...daySettings.querySelectorAll('.tour-day-end')].map(select=>[Number(select.dataset.day),select.value]));
        daySettings.innerHTML = Array.from({length:Math.max(1,Math.min(days,60))}, (_, index) => {
            const day = index + 1;
            const selectedEnd=priorEnds.get(day)||startSelect.value;
            const label=day===days?`Day ${day} finish location`:`Day ${day} overnight location`;
            const help=day===days?'Choose where the tour ends. Return to start is selected by default.':`Day ${day + 1} starts from this location.`;
            return `<div class="col-md-6"><label class="form-label" for="tour-day-end-search-${day}">Search finish and overnight places</label><input id="tour-day-end-search-${day}" type="search" class="form-control form-control-sm mb-2" autocomplete="off" placeholder="Search listed places"><label class="form-label" for="tour-day-end-${day}">${label}</label><select id="tour-day-end-${day}" class="form-select tour-day-end" data-day="${day}" required><option value="${escapeHtml(startSelect.value)}" ${selectedEnd===startSelect.value?'selected':''}>Return to start</option>${destinations.map(item => `<option value="${escapeHtml(item.id)}" ${selectedEnd===item.id?'selected':''}>${escapeHtml(item.name)}</option>`).join('')}</select><div class="form-text">${help}</div></div>`;
        }).join('');
        daySettings.querySelectorAll('input[type="search"]').forEach(input=>bindLocationSelectSearch(input,document.getElementById(input.id.replace('-search',''))));
    }
    function bindLocationSelectSearch(input, select) {
        if (!input || !select) return;
        if(input.dataset.searchBound==='true')return;
        input.dataset.searchBound='true';
        input.value='';
        input.addEventListener('input',()=>{
            const query=input.value.trim().toLocaleLowerCase();
            [...select.options].forEach(option=>{
                option.hidden=Boolean(query&&option.value!==select.value&&!option.textContent.toLocaleLowerCase().includes(query));
            });
        });
    }
    async function loadCatalogue() {
        const sameService = catalogue?.service_type_id === serviceSelect.value;
        const previousStart = sameService ? startSelect.value : '';
        const previousDestinations = new Set(sameService ? [...document.querySelectorAll('.tour-destination:checked')].map(box => box.value) : []);
        const previousActivities = new Set(sameService ? [...document.querySelectorAll('.tour-activity:checked')].map(box => box.value) : []);
        const previousDays = new Map(sameService ? [...document.querySelectorAll('.tour-place-day')].map(select => [select.id.replace('tour-place-day-', ''), select.value]) : []);
        clearError(); results.hidden = true; destinationList.innerHTML = ''; daySettings.innerHTML = ''; startSelect.innerHTML = '<option value="">Choose a listed place</option>'; document.getElementById('tour-start-search').value=''; document.getElementById('tour-destination-search').value='';
        const data = await request(`${api}/catalogue?service_type_id=${encodeURIComponent(serviceSelect.value)}`);
        catalogue = data;
        const destinations = data.destinations || [];
        startSelect.innerHTML += destinations.map(item => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.name)}</option>`).join('');
        bindLocationSelectSearch(document.getElementById('tour-start-search'),startSelect);
        if (destinations.some(item => item.id === previousStart)) startSelect.value = previousStart;
        destinationList.innerHTML = destinations.map((item, index) => `
            <div class="col-md-6"><div class="tour-option">
                <label class="form-check-label d-flex gap-2 align-items-start"><input class="form-check-input tour-destination" type="checkbox" value="${escapeHtml(item.id)}" id="tour-destination-${index}" ${previousDestinations.has(item.id)?'checked':''}><span><strong>${escapeHtml(item.name)}</strong><br><span class="small tour-muted">${escapeHtml(item.description || item.location?.city || item.location?.address || '')}</span></span></label>
                <div class="mt-2"><label class="small tour-muted" for="tour-place-day-${index}">Visit on</label><select class="form-select form-select-sm tour-place-day" id="tour-place-day-${index}">${Array.from({length:Number(document.getElementById('tour-days').value)||1},(_,day)=>`<option value="${day+1}" ${Number(previousDays.get(String(index)))===day+1?'selected':''}>Day ${day+1}</option>`).join('')}</select></div>
                ${(item.activities || []).length ? `<div class="border-top mt-3 pt-3"><p class="small fw-semibold mb-2">Activities at this place</p>${item.activities.map((activity, activityIndex) => `<label class="form-check small mb-2"><input class="form-check-input tour-activity" type="checkbox" value="${escapeHtml(activity.id)}" data-parent="${escapeHtml(item.id)}" data-destination-checkbox="tour-destination-${index}" id="tour-activity-${index}-${activityIndex}" ${previousActivities.has(activity.id)?'checked':''}><span class="form-check-label">${escapeHtml(activity.name)}${activity.visit_duration_minutes ? ` <span class="tour-muted">(${escapeHtml(activity.visit_duration_minutes)} min)</span>` : ''}${activity.description ? `<br><span class="tour-muted">${escapeHtml(activity.description)}</span>` : ''}</span></label>`).join('')}</div>` : ''}
            </div></div>`).join('');
        document.querySelectorAll('.tour-destination').forEach(box => box.addEventListener('change', () => {
            const activities=[...document.querySelectorAll(`.tour-activity[data-parent="${CSS.escape(box.value)}"]`)];
            if(box.checked){delete box.dataset.activityOnlyParent;return;}
            activities.forEach(activity=>{activity.checked=false;});
            delete box.dataset.activityOnlyParent;
        }));
        document.querySelectorAll('.tour-activity').forEach(box => box.addEventListener('change', () => {
            const parent=document.getElementById(box.dataset.destinationCheckbox);
            if(box.checked){
                if(!parent.checked){parent.dataset.activityOnlyParent='true';parent.checked=true;}
                return;
            }
            const anotherActivitySelected=[...document.querySelectorAll(`.tour-activity:checked[data-parent="${CSS.escape(box.dataset.parent)}"]`)].length>0;
            if(parent.dataset.activityOnlyParent==='true'&&!anotherActivitySelected){parent.checked=false;delete parent.dataset.activityOnlyParent;}
        }));
        filterDestinationCards();
        renderDaySettings();
    }
    function filterDestinationCards() {
        const query=document.getElementById('tour-destination-search').value.trim().toLocaleLowerCase();
        destinationList.querySelectorAll(':scope > .col-md-6').forEach(card=>{card.hidden=Boolean(query&&!card.textContent.toLocaleLowerCase().includes(query));});
    }
    function renderAiSuggestion(suggestion) {
        const destinations = new Map((catalogue?.destinations || []).map(item => [item.id, item]));
        const activities = new Map((catalogue?.destinations || []).flatMap(item => (item.activities || []).map(activity => [activity.id, { ...activity, destinationName: item.name }])));
        document.getElementById('tour-ai-preview').innerHTML = `<p class="small tour-muted mb-2">Review the suggested visits, then choose whether to add them. You can edit every selection afterward.</p>${suggestion.days.map(day => `<div class="mb-2"><strong>Day ${escapeHtml(day.day_number)}</strong><ul class="small mb-0">${day.stops.map(stop => { const place = stop.kind === 'activity' ? activities.get(stop.catalogue_id) : destinations.get(stop.catalogue_id); return `<li>${escapeHtml(place?.name || 'Published place')}${stop.kind === 'activity' && place?.destinationName ? ` <span class="tour-muted">at ${escapeHtml(place.destinationName)}</span>` : ''}</li>`; }).join('') || '<li>Choose visits manually for this day.</li>'}</ul></div>`).join('')}<div class="d-flex flex-wrap gap-2 mt-3"><button id="tour-ai-apply" class="btn btn-primary btn-sm" type="button">Add these ideas to my itinerary</button><button id="tour-ai-discard" class="btn btn-outline-secondary btn-sm" type="button">Discard ideas</button></div>`;
        const preview = document.getElementById('tour-ai-preview');
        preview.hidden = false;
        document.getElementById('tour-ai-apply').addEventListener('click', () => {
            document.querySelectorAll('.tour-destination,.tour-activity').forEach(input => { input.checked = false; });
            const dayForDestination = new Map();
            for (const day of suggestion.days) {
                for (const stop of day.stops) {
                    const activity = stop.kind === 'activity'
                        ? [...document.querySelectorAll('.tour-activity')].find(input => input.value === stop.catalogue_id)
                        : null;
                    const destinationId = stop.kind === 'destination' ? stop.catalogue_id : activity?.dataset.parent;
                    const destination = destinationId
                        ? [...document.querySelectorAll('.tour-destination')].find(input => input.value === destinationId)
                        : null;
                    if (destination) {
                        destination.checked = true;
                        dayForDestination.set(destination.value, day.day_number);
                    }
                    if (activity) activity.checked = true;
                }
            }
            for (const destination of document.querySelectorAll('.tour-destination')) {
                const day = dayForDestination.get(destination.value);
                if (day) {
                    const index = destination.id.replace('tour-destination-', '');
                    const daySelect = document.getElementById(`tour-place-day-${index}`);
                    if (daySelect) daySelect.value = String(day);
                }
            }
            selectedTemplateDays = [];
            invalidateDisplayedQuote();
            preview.hidden = true;
            document.getElementById('tour-ai-status').textContent = 'Ideas added. You can edit the places and days before building your route.';
        });
        document.getElementById('tour-ai-discard').addEventListener('click', () => {
            preview.hidden = true;
            document.getElementById('tour-ai-status').textContent = 'Ideas discarded. You can continue planning manually.';
        });
    }
    function clearAiSuggestion() {
        const preview = document.getElementById('tour-ai-preview');
        if (!preview.hidden) {
            preview.hidden = true;
            document.getElementById('tour-ai-status').textContent = 'Trip details changed. Request fresh ideas if you still want suggestions.';
        }
    }
    function itineraryDays() {
        const destinations = new Map((catalogue?.destinations || []).map(item => [item.id, item]));
        const start = destinations.get(startSelect.value);
        if (!start) throw new Error('Choose a start and finish location.');
        const count = Number(document.getElementById('tour-days').value);
        if (!Number.isInteger(count) || count < 1 || count > 60) throw new Error('Choose between 1 and 60 trip days.');
        const selected = [...document.querySelectorAll('.tour-destination:checked')];
        if (!selected.length) throw new Error('Choose at least one place to visit.');
        const endByDay = new Map();
        document.querySelectorAll('.tour-day-end').forEach(select => endByDay.set(Number(select.dataset.day), select.value));
        return Array.from({length:count}, (_, index) => {
            const day = index + 1;
            const templateDay=selectedTemplateDays.find(item=>Number(item.day_number)===day);
            const startId = day === 1 ? start.id : endByDay.get(day - 1);
            const endId = endByDay.get(day);
            if (!startId || !endId) throw new Error('Choose an overnight location for each day.');
            const stops = [];
            const templateStop=(kind,id)=>templateDay?.stops?.find(item=>item.kind===kind&&item.catalogue_id===id)||{};
            selected.filter(box => Number(document.getElementById(box.id.replace('tour-destination-', 'tour-place-day-')).value) === day).forEach(box => {
                const destination = destinations.get(box.value);
                if (destination) { const preset=templateStop('destination',destination.id); stops.push({kind:'destination',catalogue_id:destination.id,is_locked:!!preset.is_locked,arrival_time:preset.arrival_time||null}); }
                document.querySelectorAll(`.tour-activity:checked[data-parent="${CSS.escape(box.value)}"]`).forEach(activity => { const preset=templateStop('activity',activity.value); stops.push({kind:'activity',catalogue_id:activity.value,is_locked:!!preset.is_locked,arrival_time:preset.arrival_time||null}); });
            });
            const startPlace = destinations.get(startId);
            return {day_number:day,timezone:templateDay?.timezone||startPlace?.timezone||'Asia/Colombo',start_time:templateDay?.start_time||document.getElementById('tour-time').value,start_destination_id:startId,end_destination_id:endId,overnight_destination_id:day < count ? endId : null,is_locked:!!templateDay?.is_locked,stops};
        });
    }
    function renderRoute(data) {
        const unavailable = data.status !== 'ready';
        const renderAggregate = (route, label) => {
            const canQuote=route.quote?.status==='priced'&&!unavailable;
            const disabledLabel=route.quote?.status==='quotation_required'||unavailable?'Staff review required':'Price needs staff review';
            return `<div class="col-md-6"><div class="tour-option h-100"><h3 class="h6">${escapeHtml(label)}</h3><p><strong>${route.distance_km == null ? 'Distance unavailable' : `${escapeHtml(route.distance_km)} km total`}</strong>${route.duration_seconds != null ? ` · ${Math.round(route.duration_seconds / 3600 * 10) / 10} hr driving` : ''}</p>${route.quote?.status === 'priced' ? `<p class="h5">${escapeHtml(route.quote.currency)} ${Number(route.quote.total_amount).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</p>` : `<p class="small text-warning">${escapeHtml(route.quote?.reason || 'Price needs staff review.')}</p>`}<div>${(route.days || []).map(day=>`<p class="small mb-1">Day ${day.day_number}: ${escapeHtml((day.schedule||[]).map(stop=>stop.stop_name).join(' → '))}</p>`).join('')}</div><button type="button" class="btn btn-outline-primary mt-2 tour-use-route" data-route="${label === 'Suggested route' ? 'suggested' : 'original'}" ${canQuote?'':'disabled'}>${canQuote?'Use this route and review price':disabledLabel}</button></div></div>`;
        };
        return `<div class="alert ${data.status === 'unavailable' ? 'alert-warning' : 'alert-info'}">${data.status === 'unavailable' ? 'We could not verify every road route. You can save this plan and ask our team to review it.' : data.changed ? 'Compare the original order with the suggested order for every day. Every selected destination and activity stays in the plan.' : 'Your selected order is already the best route we found.'}</div><div class="row g-3">${renderAggregate(data.original,'Your selected order')}${renderAggregate(data.suggested,'Suggested route')}</div><div class="small tour-muted mt-3">${(data.issues||[]).map(issue=>`<p class="mb-1">Day ${issue.day_number}: ${escapeHtml(issue.message)}</p>`).join('')}<p class="mb-0">Activity visits are included in your route only. Tickets, entry fees and activity reservations are not included. Please arrange these separately. Activity booking will be introduced in a future release.</p></div><div class="mt-3"><button type="button" id="tour-request-review" class="btn btn-outline-primary">Ask our team to review this Tour</button><p class="small tour-muted mt-2 mb-0">This sends your itinerary for follow-up only. It does not book a vehicle or take payment.</p></div>`;
    }
    form.addEventListener('submit', async event => {
        event.preventDefault(); clearError(); invalidateDisplayedQuote();
        const button = document.getElementById('tour-plan'); button.disabled = true; document.getElementById('tour-working').hidden = false;
        const busyControls=[...form.querySelectorAll('input,select,textarea,button')].filter(control=>control!==button);
        const priorDisabled=busyControls.map(control=>control.disabled);
        busyControls.forEach(control=>{control.disabled=true;});
        try {
            const payload = {
                first_name:document.getElementById('tour-first-name').value,
                last_name:document.getElementById('tour-last-name').value,
                email:document.getElementById('tour-email').value,
                phone:document.getElementById('tour-phone').value,
                service_type_id: serviceSelect.value,
                vehicle_class_id: classSelect.value,
                service_package_id: packageSelect.value || null,
                start_date: document.getElementById('tour-date').value,
                duration_days: Number(document.getElementById('tour-days').value),
                adult_count: Number(document.getElementById('tour-adults').value),
                child_count: Number(document.getElementById('tour-children').value),
                luggage_count: Number(document.getElementById('tour-luggage').value),
                days:itineraryDays(),
                custom_answers:Object.fromEntries([...document.querySelectorAll('[data-custom-name]')].reduce((entries,input)=>{if(input.type==='radio'){if(input.checked)entries.push([input.dataset.customName,input.value]);}else entries.push([input.dataset.customName,input.type==='checkbox'?input.checked:input.value]);return entries;},[])),
            };
            if (!payload.vehicle_class_id) throw new Error('Choose a vehicle class.');
            const created = await request('/tours/drafts', {method:'POST',body:JSON.stringify(payload)});
            savedItinerary = created.itinerary;
            const routes = created.route_options;
            results.innerHTML = renderRoute(routes) + `<div id="tour-quote" class="mt-4"></div>`;
            results.hidden = false;
            document.getElementById('tour-request-review')?.addEventListener('click', async event => {
                const requestButton = event.currentTarget;
                requestButton.disabled = true;
                try {
                    const review = await request(`/tours/drafts/${savedItinerary.id}/request-review`, {method:'POST',body:JSON.stringify({})});
                    window.location.assign(review.confirmation_url);
                } catch (exception) {
                    showError(exception.message || 'We could not send your Tour for staff review. Please try again.');
                    requestButton.disabled = false;
                }
            });
            results.querySelectorAll('.tour-use-route').forEach(button => button.addEventListener('click', async () => {
                let quoteReady = false;
                button.disabled = true;
                results.querySelectorAll('.tour-use-route').forEach(option => { option.disabled = true; });
                try {
                    if (button.dataset.route === 'suggested' && routes.changed) {
                        const routeDays = (routes.days || []).map(day=>({day_number:day.day_number,stop_ids:day.suggested_stop_ids||[]}));
                        const applied = await request(`/tours/drafts/${savedItinerary.id}/route`, {method:'POST',body:JSON.stringify({expected_revision:savedItinerary.revision,days:routeDays})});
                        savedItinerary = applied;
                    }
                    const quote = await request(`/tours/drafts/${savedItinerary.id}/quote`, {method:'POST',body:JSON.stringify({expected_revision:savedItinerary.revision})});
                    const q=quote;
                    const amount=Number(q.total_amount).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
                    showQuoteSummary(q);
                    document.getElementById('tour-quote').innerHTML=`<div class="alert alert-success"><h3 class="h5">Transport price for your selected route</h3><p class="h4">${escapeHtml(q.currency)} ${amount}</p><p>Estimated route distance: ${escapeHtml(q.distance_km)} km. The price is held until ${escapeHtml(new Date(q.expires_at).toLocaleString())}. Vehicle availability is checked when you submit your booking through checkout.</p>${(q.terms_snapshot||[]).map(term=>`<div class="form-check mb-2"><input class="form-check-input tour-term" type="checkbox" value="${escapeHtml(term.id)}" id="tour-term-${escapeHtml(term.id)}"><label class="form-check-label" for="tour-term-${escapeHtml(term.id)}"><strong>${escapeHtml(term.title||'Booking terms')}</strong> (${escapeHtml(term.version||'current')}) — I agree</label><div class="small tour-muted ms-4">${escapeHtml(term.content)}</div></div>`).join('')}<button class="btn btn-primary mt-3" id="tour-accept-quote" type="button">Accept price and continue to checkout</button></div>`;
                    quoteReady = true;
                    document.getElementById('tour-accept-quote').addEventListener('click', async () => {
                        const terms=document.querySelectorAll('.tour-term');
                        if ([...terms].some(item=>!item.checked)) { showError('Please accept each required booking term to continue.'); return; }
                        const acceptButton=document.getElementById('tour-accept-quote'); acceptButton.disabled=true;
                        try {
                            await request(`/tours/drafts/${savedItinerary.id}/quotes/${q.id}/accept`,{method:'POST',body:JSON.stringify({expected_revision:savedItinerary.revision,accept_terms:true})});
                            const checkout=await request(`/tours/drafts/${savedItinerary.id}/booking`,{method:'POST',body:JSON.stringify({quote_id:q.id,expected_revision:savedItinerary.revision})});
                            window.location.assign(checkout.checkout_url);
                        } catch(exception) { showError(exception.message || 'We could not submit the Tour booking. Your accepted quote is saved so you can try again.'); acceptButton.disabled=false; }
                    });
                } catch (exception) {
                    showError(exception.message || 'We could not prepare the quote. Please review your route and try again.');
                } finally { results.querySelectorAll('.tour-use-route').forEach(option => { option.disabled = quoteReady; }); }
            }));
        } catch (exception) { showError(exception.message || 'We could not plan this route. Please check your choices and try again.'); }
        finally { busyControls.forEach((control,index)=>{control.disabled=priorDisabled[index];}); button.disabled = false; document.getElementById('tour-working').hidden = true; }
    });
    serviceSelect.addEventListener('change', async () => { selectedTemplateDays=[]; fillOptions(); try { await loadCatalogue(); } catch (exception) { showError(exception.message); } });
    packageSelect.addEventListener('change', () => { clearAiSuggestion(); limitPackageClasses(); applyPackageTemplate(); });
    document.getElementById('tour-destination-search').addEventListener('input', filterDestinationCards);
    form.addEventListener('input', event => {
        if (results.contains(event.target) || event.target.closest('#tour-ai-helper') || event.target.type === 'search') return;
        invalidateDisplayedQuote();
    });
    form.addEventListener('change', event => {
        if (results.contains(event.target) || event.target.closest('#tour-ai-helper') || event.target.type === 'search') return;
        invalidateDisplayedQuote();
    });
    ['tour-adults','tour-children','tour-luggage'].forEach(id=>document.getElementById(id).addEventListener('input',()=>{renderClassCards();results.hidden=true;}));
    document.getElementById('tour-date').addEventListener('change', clearAiSuggestion);
    document.getElementById('tour-interests').addEventListener('input', clearAiSuggestion);
    document.getElementById('tour-days').addEventListener('change', () => {
        clearAiSuggestion();
        const count=Math.max(1,Math.min(60,Number(document.getElementById('tour-days').value)||1));
        document.querySelectorAll('.tour-place-day').forEach(select=>{
            const selected=Math.min(count,Number(select.value)||1);
            select.innerHTML=Array.from({length:count},(_,day)=>`<option value="${day+1}" ${selected===day+1?'selected':''}>Day ${day+1}</option>`).join('');
        });
        renderDaySettings(); invalidateDisplayedQuote();
    });
    startSelect.addEventListener('change', renderDaySettings);
    document.getElementById('tour-send-code').addEventListener('click', async () => {
        const email=document.getElementById('tour-email').value.trim();
        if (!email) { showError('Enter your email first.'); return; }
        const status=document.getElementById('tour-code-status');
        try { const response=await request('/checkout/customer-email/send-code',{method:'POST',body:JSON.stringify({channel:'email',value:email})}); status.textContent=response.message||'If these details match a previous booking, check your email for a code.'; document.getElementById('tour-code-panel').hidden=false; }
        catch(exception){status.textContent=exception.message;}
    });
    document.getElementById('tour-verify-code').addEventListener('click', async () => {
        const status=document.getElementById('tour-code-status');
        try { const customer=await request('/checkout/customer-email/verify-code',{method:'POST',body:JSON.stringify({channel:'email',value:document.getElementById('tour-email').value.trim(),code:document.getElementById('tour-code').value.trim()})}); for(const [field,id] of [['first_name','tour-first-name'],['last_name','tour-last-name'],['email','tour-email'],['phone','tour-phone']]) if(customer[field])document.getElementById(id).value=customer[field]; status.textContent='Your details are verified.'; }
        catch(exception){status.textContent=exception.message;}
    });
    document.getElementById('tour-ai-suggest').addEventListener('click', async event => {
        const button = event.currentTarget;
        const status = document.getElementById('tour-ai-status');
        const requestData = {
            service_type_id: serviceSelect.value,
            duration_days: Number(document.getElementById('tour-days').value),
            start_date: document.getElementById('tour-date').value || null,
            interests: document.getElementById('tour-interests').value.trim(),
        };
        if (requestData.duration_days > 14) {
            status.textContent = 'AI ideas support trips up to 14 days. You can still plan longer trips manually.';
            return;
        }
        const requestKey = JSON.stringify(requestData);
        button.disabled = true;
        status.textContent = 'Preparing ideas from published places…';
        try {
            const response = await request('/api/public/tours/suggestions', {method:'POST',body:JSON.stringify(requestData)});
            if (serviceSelect.value !== requestData.service_type_id
                || JSON.stringify({service_type_id:serviceSelect.value,duration_days:Number(document.getElementById('tour-days').value),start_date:document.getElementById('tour-date').value||null,interests:document.getElementById('tour-interests').value.trim()}) !== requestKey) {
                status.textContent = 'Trip details changed while ideas were loading. Request fresh ideas if you still want them.';
                return;
            }
            renderAiSuggestion(response);
            status.textContent = response.notice || 'Review the draft before adding it to your plan.';
        } catch (exception) {
            status.textContent = exception.message || 'Suggestions are unavailable. You can continue planning manually.';
        } finally {
            button.disabled = false;
        }
    });
    document.getElementById('tour-mobile-review').addEventListener('click',()=>{
        document.getElementById('tour-quote')?.scrollIntoView({behavior:'smooth',block:'center'});
        document.getElementById('tour-accept-quote')?.focus({preventScroll:true});
    });

    const tomorrow = new Date(); tomorrow.setDate(tomorrow.getDate()+1); document.getElementById('tour-date').min = `${tomorrow.getFullYear()}-${String(tomorrow.getMonth()+1).padStart(2,'0')}-${String(tomorrow.getDate()).padStart(2,'0')}`;
    request(`${api}/services`).then(data => {
        services = data.services || [];
        if (!services.length) throw new Error('Tours are not available at the moment.');
        fillServices(); fillOptions(); return loadCatalogue();
    }).then(() => { loading.hidden = true; form.hidden = false; }).catch(exception => { loading.hidden = true; showError(exception.message); });
})();
</script>
@endpush
