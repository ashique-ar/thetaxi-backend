import fs from 'node:fs';

const read = (path) => JSON.parse(fs.readFileSync(path, 'utf8'));
const write = (path, value) => fs.writeFileSync(path, `${JSON.stringify(value, null, 4)}\n`);

const collectionPath = 'docs/postman/Driver-API.postman_collection.json';
const openApiPath = 'public/docs/driver-mobile-api.openapi.json';
const collection = read(collectionPath);
const openapi = read(openApiPath);

const bearer = { type: 'bearer', bearer: [{ key: 'token', value: '{{access_token}}', type: 'string' }] };
const jsonHeaders = [
    { key: 'Accept', value: 'application/json', type: 'text' },
    { key: 'Content-Type', value: 'application/json', type: 'text' },
];

function response(name, code, body) {
    return {
        name,
        originalRequest: {},
        status: name,
        code,
        _postman_previewlanguage: 'json',
        header: [{ key: 'Content-Type', value: 'application/json' }],
        cookie: [],
        body: JSON.stringify(body, null, 4),
    };
}

function requestItem(name, method, raw, body, description, responses) {
    const pathAndQuery = raw.replace('{{base_url}}/', '').split('?');
    const item = {
        name,
        request: {
            auth: bearer,
            method,
            header: jsonHeaders,
            url: {
                raw,
                host: ['{{base_url}}'],
                path: pathAndQuery[0].split('/'),
            },
            description,
        },
        response: responses,
    };
    if (body !== undefined) {
        item.request.body = { mode: 'raw', raw: JSON.stringify(body, null, 4), options: { raw: { language: 'json' } } };
    }
    return item;
}

function upsertAfter(folderName, afterName, item) {
    const folder = collection.item.find((entry) => entry.name === folderName);
    if (!folder) throw new Error(`Postman folder not found: ${folderName}`);
    folder.item = folder.item.filter((entry) => entry.name !== item.name);
    const index = folder.item.findIndex((entry) => entry.name === afterName);
    folder.item.splice(index < 0 ? folder.item.length : index + 1, 0, item);
}

const versionGet = requestItem(
    'Version Check (GET)',
    'GET',
    '{{base_url}}/api/driver/version-check?version={{app_version}}&build_number={{app_build}}&platform={{platform}}',
    undefined,
    'GET form of the canonical public pre-login version check. The POST request is preferred by the mobile app; both methods execute the same server contract.',
    [response('OK', 200, { status: 'success', data: { current_version: '1.2.0', latest_version: '1.3.0', update_required: true, current_build_number: 120, mandatory_update: false, can_continue: true, release_policy: { android_package_id: 'com.example.driver', advertised_release_published: true, mandatory_release_validated: false }, message: 'A new driver app version is available. Please update to continue.' } })],
);
versionGet.request.auth = { type: 'noauth' };
upsertAfter('App Settings', 'Version Check', versionGet);

const forgotPassword = requestItem('Forgot Password', 'POST', '{{base_url}}/api/driver/auth/forgot-password',
    { email: 'driver@example.com' }, 'Emails a six-digit password reset OTP. The response never reveals whether the account exists.', [response('OK', 200, { status: 'success', message: 'If an eligible driver account exists, a password reset OTP has been sent.' })]);
forgotPassword.request.auth = { type: 'noauth' };
upsertAfter('Authentication', 'Login', forgotPassword);
const resetPassword = requestItem('Reset Password', 'POST', '{{base_url}}/api/driver/auth/reset-password',
    { email: 'driver@example.com', otp: '123456', password: 'NewPassword1!', password_confirmation: 'NewPassword1!' },
    'Resets an eligible driver password, clears lockout state, and revokes all sessions.', [response('OK', 200, { status: 'success', message: 'Password reset successfully. Please sign in again.' })]);
resetPassword.request.auth = { type: 'noauth' };
upsertAfter('Authentication', 'Forgot Password', resetPassword);
upsertAfter('Authentication', 'Profile', requestItem('Change Password', 'POST', '{{base_url}}/api/driver/auth/change-password',
    { current_password: 'CurrentPassword1!', new_password: 'NewPassword1!', new_password_confirmation: 'NewPassword1!' },
    'Changes the authenticated driver password and revokes every existing session/device.', [response('OK', 200, { status: 'success', message: 'Password changed successfully. Please sign in again.', data: { reauthentication_required: true } })]));

upsertAfter('Location Tracking', 'Bulk Upload Buffered Locations', requestItem(
    'Report Location Health',
    'POST',
    '{{base_url}}/api/driver/location/health',
    {
        state: 'recovered',
        queue_count: 0,
        oldest_queue_age_seconds: 0,
        last_fix_age_seconds: 5,
        app_version: '{{app_version}}',
        app_build: '{{app_build}}',
    },
    'Reports mobile GPS queue and fix health to operations monitoring. Use state values healthy, recovered, delayed, severe_gap, blocked, or queue_pressure.',
    [response('OK', 200, { status: 'success', data: { acknowledged: true } })],
));

upsertAfter('Booking Assignments', 'Accept Assignment', requestItem(
    'Acknowledge Assignment Notification',
    'POST',
    '{{base_url}}/api/driver/assignments/{{assignment_id}}/acknowledge',
    {},
    'Records that the authenticated driver opened the assignment notification. This does not accept the assignment; call accept separately when allowed.',
    [response('OK', 200, {
        status: 'success',
        data: {
            notification_id: 'notification-uuid',
            acknowledged_at: '2026-09-02T11:30:00+05:30',
            acknowledgement_source: 'opened',
            acknowledgement_required: false,
        },
    })],
));

const bookingPartyExample = {
    type: 'corporate',
    account_name: 'Acme Holdings',
    traveler_type: 'employee',
    traveler_name: 'Nimal Perera',
    traveler_phone: '+94771111111',
    traveler_email: 'nimal@example.com',
};

function enhanceExamples(value) {
    if (!value || typeof value !== 'object') return;
    // trip_mode=open_package is the only metered-hire discriminator.
    delete value.execution_mode;
    delete value.uses_hire_meter;
    if (!Array.isArray(value) && value.id && value.booking_id && value.booking_item_id && value.service_type_name) {
        const openPackage = value.trip_mode === 'open_package';
        value.pricing_visible = value.pricing_visible ?? true;
        value.booking_party = value.booking_party ?? bookingPartyExample;
        value.service = value.service ?? {
            id: 'service-type-uuid', code: openPackage ? 'day_rental' : 'airport_transfer',
            name: value.service_type_name, type: 'with_driver',
        };
        value.execution_capabilities = value.execution_capabilities ?? {
            requires_driver: true,
            route_mode: openPackage ? 'open_package' : 'fixed_route',
            requires_destination: !openPackage,
            supports_multiple_stops: Boolean(value.is_multi_stop),
            tracks_waiting: true,
            collects_payment: true,
            shows_pricing: true,
        };
    }
    if (!Array.isArray(value) && Object.hasOwn(value, 'employee_id') && Object.hasOwn(value, 'contact_name')) {
        value.kind = value.kind ?? (value.employee_id ? 'corporate_employee' : 'external_contact');
        value.name = value.name ?? value.contact_name;
        value.phone = value.phone ?? value.contact_phone;
        value.note = value.note ?? value.contact_note;
    }
    if (!Array.isArray(value) && value.hire_completed === true) {
        value.pricing_visible = value.pricing_visible ?? true;
    }
    if (!Array.isArray(value) && Object.hasOwn(value, 'collection_required')) {
        value.pricing_visible = value.pricing_visible ?? Boolean(value.collection_required || Object.hasOwn(value, 'final_amount'));
    }
    for (const child of Object.values(value)) enhanceExamples(child);
}

for (const folder of collection.item) {
    for (const item of folder.item ?? []) {
        for (const saved of item.response ?? []) {
            try {
                const parsed = JSON.parse(saved.body);
                enhanceExamples(parsed);
                saved.body = JSON.stringify(parsed, null, 4);
            } catch (_) {}
        }
    }
}

openapi.info.version = '2.7.0';
openapi.info.description = 'Complete canonical Driver Mobile API contract. Authentication and account/profile responses include the driver profile image, assigned vehicle, vehicle images, and driver/vehicle documents. Assignment projections expose server-owned service capabilities and traveler/contact identity. Pricing is visible only when the driver must collect payment. Complete-trip calculates and stores the final amount first; cash collection uses the separate collect-payment endpoint only when `payment.collection_required` is true.';

const nullableString = (format) => ({ type: 'string', nullable: true, ...(format ? { format } : {}) });
openapi.components.schemas.DriverMobileDocument = {
    type: 'object',
    required: ['id', 'type', 'url', 'resource_url'],
    properties: {
        id: { type: 'string', format: 'uuid' },
        type: nullableString(), file_name: nullableString(), mime_type: nullableString(),
        file_size: { type: 'integer', nullable: true }, status: nullableString(),
        expiry_date: nullableString('date'), url: nullableString('uri'), resource_url: nullableString('uri'),
        updated_at: nullableString('date-time'),
    },
};
openapi.components.schemas.DriverVehicleImage = {
    type: 'object',
    description: 'Vehicle image metadata saved by vehicle management. Use url when present; path is the persisted fallback.',
    properties: {
        path: nullableString(), url: nullableString('uri'), name: nullableString(),
        size: { type: 'number', nullable: true }, type: nullableString(),
        isImage: { type: 'boolean', nullable: true }, is_primary: { type: 'boolean', nullable: true },
        uploaded_at: nullableString('date-time'),
    },
    additionalProperties: true,
};
openapi.components.schemas.DriverVehicle = {
    type: 'object',
    description: 'The vehicle currently assigned as the driver default vehicle.',
    required: ['id', 'is_active', 'images', 'documents'],
    properties: {
        id: { type: 'string', format: 'uuid' }, title: nullableString(), registration_no: nullableString(),
        license_plate: nullableString(), model_year: { type: 'integer', nullable: true },
        registration_year: { type: 'integer', nullable: true }, color: nullableString(), ownership_type: nullableString(),
        is_active: { type: 'boolean' }, availability_status: nullableString(), vehicle_group_id: nullableString('uuid'),
        vehicle_group: { type: 'object', nullable: true, properties: { id: { type: 'string', format: 'uuid' }, name: { type: 'string' } } },
        make: { type: 'object', nullable: true, properties: { id: { type: 'string', format: 'uuid' }, name: { type: 'string' } } },
        model: { type: 'object', nullable: true, properties: { id: { type: 'string', format: 'uuid' }, name: { type: 'string' } } },
        thumbnail: { oneOf: [{ $ref: '#/components/schemas/DriverVehicleImage' }, { type: 'array', items: { $ref: '#/components/schemas/DriverVehicleImage' } }], nullable: true },
        images: { type: 'array', items: { $ref: '#/components/schemas/DriverVehicleImage' } },
        documents: { type: 'array', items: { $ref: '#/components/schemas/DriverMobileDocument' } },
    },
};
openapi.components.schemas.DriverMobileAccount = {
    type: 'object',
    description: 'Canonical driver account returned by password login, OTP login, and GET /auth/profile.',
    required: ['id', 'user_id', 'full_name', 'profile_image_url', 'profile_image', 'profile_photo_url', 'profile_photo', 'documents', 'assigned_vehicle', 'vehicle'],
    properties: {
        id: { type: 'string', format: 'uuid' }, user_id: { type: 'string', format: 'uuid' }, code: nullableString(),
        employee_id: nullableString(), first_name: nullableString(), last_name: nullableString(), full_name: { type: 'string' },
        email: nullableString('email'), phone: nullableString(), nic: nullableString(), license_no: nullableString(),
        license_number: nullableString(), license_expiry: nullableString('date'), license_status: { type: 'string', enum: ['missing', 'expired', 'expiring', 'valid'] },
        default_vehicle_id: nullableString('uuid'),
        profile_image_url: nullableString('uri'), profile_image: { allOf: [{ $ref: '#/components/schemas/DriverMobileDocument' }], nullable: true },
        profile_photo_url: nullableString('uri'), profile_photo: { allOf: [{ $ref: '#/components/schemas/DriverMobileDocument' }], nullable: true },
        documents: { type: 'array', items: { $ref: '#/components/schemas/DriverMobileDocument' } },
        is_active: { type: 'boolean' }, is_online: { type: 'boolean' }, availability_status: nullableString(),
        current_booking_id: nullableString('uuid'), current_booking_number: nullableString(), rating: { type: 'number', format: 'float' }, total_trips: { type: 'integer' },
        assigned_vehicle: { allOf: [{ $ref: '#/components/schemas/DriverVehicle' }], nullable: true },
        vehicle: { allOf: [{ $ref: '#/components/schemas/DriverVehicle' }], nullable: true },
    },
    additionalProperties: true,
};

openapi.components.schemas.DriverBookingParty = {
    type: 'object',
    description: 'The traveler the driver should contact. Corporate employee/general-contact identity takes precedence over the account owner.',
    required: ['type', 'traveler_type', 'traveler_name'],
    properties: {
        type: { type: 'string', enum: ['individual', 'corporate'] },
        account_name: { type: 'string', nullable: true },
        traveler_type: { type: 'string', enum: ['customer', 'employee', 'general_contact'] },
        traveler_name: { type: 'string', nullable: true },
        traveler_phone: { type: 'string', nullable: true },
        traveler_email: { type: 'string', format: 'email', nullable: true },
    },
};

const executionCapabilitiesSchema = openapi.components.schemas.DriverExecutionCapabilities;
if (executionCapabilitiesSchema) {
    executionCapabilitiesSchema.required = (executionCapabilitiesSchema.required ?? [])
        .filter((field) => !['execution_mode', 'uses_hire_meter'].includes(field));
    delete executionCapabilitiesSchema.properties?.execution_mode;
    delete executionCapabilitiesSchema.properties?.uses_hire_meter;
}

function enhanceSchemas(value) {
    if (!value || typeof value !== 'object') return;
    const properties = value.properties;
    if (properties && properties.booking_number && properties.service_type_name) {
        properties.pricing_visible = { type: 'boolean', description: 'True only when monetary fields may be shown to the driver.' };
        properties.booking_party = { $ref: '#/components/schemas/DriverBookingParty' };
        properties.service = { $ref: '#/components/schemas/DriverAssignmentService' };
        properties.execution_capabilities = { $ref: '#/components/schemas/DriverExecutionCapabilities' };
        if (properties.fare_amount) properties.fare_amount.nullable = true;
        if (properties.total_amount) properties.total_amount.nullable = true;
        if (properties.currency) properties.currency.nullable = true;
    }
    if (properties && properties.employee_id && properties.contact_name) {
        properties.kind = { type: 'string', enum: ['corporate_employee', 'external_contact'] };
        properties.name = { type: 'string', nullable: true };
        properties.phone = { type: 'string', nullable: true };
        properties.note = { type: 'string', nullable: true };
    }
    if (properties && properties.hire_completed) {
        properties.pricing_visible = { type: 'boolean', description: 'Controls whether final_pricing and package_charges are exposed.' };
        if (properties.final_pricing) properties.final_pricing.nullable = true;
        if (properties.package_charges) properties.package_charges.nullable = true;
    }
    if (properties && properties.collection_required) {
        properties.pricing_visible = { type: 'boolean', description: 'False for office, corporate-account, or already-settled payment arrangements.' };
    }
    for (const child of Object.values(value)) enhanceSchemas(child);
}

enhanceSchemas(openapi);
enhanceExamples(openapi);

const protectedSecurity = [{ bearerAuth: [] }];
const errorSchema = {
    type: 'object',
    properties: {
        status: { type: 'string', example: 'error' },
        message: { type: 'string' },
        error_code: { type: 'string' },
        errors: { type: 'object', additionalProperties: true },
    },
};
const jsonResponse = (description, schema, example) => ({
    description,
    content: { 'application/json': { schema, ...(example ? { example } : {}) } },
});
const errorResponse = (description) => jsonResponse(description, errorSchema, { message: description });

const passwordSchema = { type: 'string', format: 'password', minLength: 8 };
openapi.paths['/api/driver/auth/forgot-password'] = {
    post: { tags: ['Authentication'], summary: 'Request driver password reset', security: [],
        requestBody: { required: true, content: { 'application/json': { schema: { type: 'object', required: ['email'], properties: { email: { type: 'string', format: 'email' } } } } } },
        responses: { 200: jsonResponse('Enumeration-safe acknowledgement', { type: 'object', properties: { status: { type: 'string' }, message: { type: 'string' } } }, { status: 'success', message: 'If an eligible driver account exists, a password reset OTP has been sent.' }), 422: errorResponse('Invalid email format'), 429: errorResponse('Rate limited') } },
};
openapi.paths['/api/driver/auth/reset-password'] = {
    post: { tags: ['Authentication'], summary: 'Reset driver password', security: [],
        requestBody: { required: true, content: { 'application/json': { schema: { type: 'object', required: ['email', 'otp', 'password', 'password_confirmation'], properties: { email: { type: 'string', format: 'email' }, otp: { type: 'string', pattern: '^\\d{6}$', example: '123456' }, password: passwordSchema, password_confirmation: passwordSchema } } } } },
        responses: { 200: jsonResponse('Password reset; all sessions revoked', { type: 'object', properties: { status: { type: 'string' }, message: { type: 'string' } } }, { status: 'success', message: 'Password reset successfully. Please sign in again.' }), 422: errorResponse('Invalid or expired OTP'), 429: errorResponse('Rate limited') } },
};
openapi.paths['/api/driver/auth/change-password'] = {
    post: { tags: ['Authentication'], summary: 'Change driver password', security: protectedSecurity,
        requestBody: { required: true, content: { 'application/json': { schema: { type: 'object', required: ['current_password', 'new_password', 'new_password_confirmation'], properties: { current_password: { type: 'string', format: 'password' }, new_password: passwordSchema, new_password_confirmation: passwordSchema } } } } },
        responses: { 200: jsonResponse('Password changed; reauthentication required', { type: 'object', properties: { status: { type: 'string' }, message: { type: 'string' }, data: { type: 'object', properties: { reauthentication_required: { type: 'boolean' } } } } }, { status: 'success', message: 'Password changed successfully. Please sign in again.', data: { reauthentication_required: true } }), 401: errorResponse('Unauthenticated'), 422: errorResponse('Current password or validation failure'), 429: errorResponse('Rate limited') } },
};

openapi.paths['/api/driver/location/health'] = {
    post: {
        tags: ['Location Tracking'],
        summary: 'Report mobile location health',
        description: 'Reports GPS queue/fix health to operations monitoring. This is telemetry acknowledgement and does not upload route points.',
        security: protectedSecurity,
        requestBody: {
            required: true,
            content: { 'application/json': { schema: {
                type: 'object', required: ['state', 'queue_count'], properties: {
                    state: { type: 'string', enum: ['healthy', 'recovered', 'delayed', 'severe_gap', 'blocked', 'queue_pressure'] },
                    queue_count: { type: 'integer', minimum: 0 },
                    oldest_queue_age_seconds: { type: 'integer', minimum: 0, nullable: true },
                    last_fix_age_seconds: { type: 'integer', minimum: 0, nullable: true },
                    app_version: { type: 'string', maxLength: 50, nullable: true },
                    app_build: { type: 'string', maxLength: 50, nullable: true },
                },
            }, example: { state: 'recovered', queue_count: 0, oldest_queue_age_seconds: 0, last_fix_age_seconds: 5, app_version: '1.2.0', app_build: '120' } } },
        },
        responses: {
            200: { description: 'Health report acknowledged', content: { 'application/json': { schema: { type: 'object', properties: { status: { type: 'string' }, data: { type: 'object', properties: { acknowledged: { type: 'boolean' } } } } }, example: { status: 'success', data: { acknowledged: true } } } } },
            403: { description: 'Authenticated user is not a driver', content: { 'application/json': { schema: errorSchema } } },
            422: { description: 'Validation error', content: { 'application/json': { schema: errorSchema } } },
        },
    },
};

if (openapi.paths['/api/driver/version-check']?.post) {
    const postVersionCheck = openapi.paths['/api/driver/version-check'].post;
    openapi.paths['/api/driver/version-check'].get = {
        tags: postVersionCheck.tags,
        summary: 'Check driver app version (GET)',
        description: 'GET form of the canonical public pre-login version check. POST is preferred; both methods return the same response contract.',
        security: [],
        parameters: [
            { name: 'version', in: 'query', required: true, schema: { type: 'string', maxLength: 50 }, example: '1.2.0' },
            { name: 'build_number', in: 'query', required: false, schema: { type: 'integer', minimum: 1 }, example: 120 },
            { name: 'platform', in: 'query', required: false, schema: { type: 'string', maxLength: 50 }, example: 'android' },
        ],
        responses: postVersionCheck.responses,
    };
}

openapi.paths['/api/driver/assignments/{assignment_id}/acknowledge'] = {
    post: {
        tags: ['Booking Assignments'],
        summary: 'Acknowledge assignment notification',
        description: 'Records that the driver opened the assignment notification. It does not accept the assignment.',
        security: protectedSecurity,
        parameters: [{ name: 'assignment_id', in: 'path', required: true, schema: { type: 'string', format: 'uuid' } }],
        responses: {
            200: { description: 'Notification acknowledged', content: { 'application/json': { schema: { type: 'object', properties: { status: { type: 'string' }, data: { type: 'object', properties: { notification_id: { type: 'string', format: 'uuid' }, acknowledged_at: { type: 'string', format: 'date-time', nullable: true }, acknowledgement_source: { type: 'string', example: 'opened' }, acknowledgement_required: { type: 'boolean', example: false } } } } } } } },
            403: { description: 'Authenticated user is not a driver', content: { 'application/json': { schema: errorSchema } } },
            404: { description: 'Assignment not found', content: { 'application/json': { schema: errorSchema } } },
            409: { description: 'Assignment notification cannot be acknowledged', content: { 'application/json': { schema: errorSchema } } },
        },
    },
};

const onboardingFolder = {
    name: 'Driver Onboarding',
    item: [
        requestItem('Request Mobile OTP', 'POST', '{{base_url}}/api/driver/onboarding/request-otp', { mobile: '+94771234567' }, 'Send a six-digit SMS OTP. Expires after 10 minutes.', [response('OK', 200, { status: 'success', message: 'OTP sent.', data: { expires_in: 600 } })]),
        requestItem('Verify Mobile OTP', 'POST', '{{base_url}}/api/driver/onboarding/verify-otp', {
            mobile: '+94771234567', otp: '123456', device_uuid: 'device-uuid', device_fingerprint: 'fingerprint',
            device_name: 'Nimal phone', device_model: 'Pixel 9', device_manufacturer: 'Google', platform: 'android',
            os_version: '16', app_version: '{{app_version}}', app_build: '{{app_build}}', push_token: 'fcm-token',
            push_provider: 'fcm', locale: 'en-LK', timezone: 'Asia/Colombo',
        }, 'Verify mobile. Existing drivers receive the normal login response; other users receive an onboarding token and prefilled application.', [
            response('Registration Created', 201, { status: 'success', data: { flow: 'registration', onboarding_token: 'token', application: { id: 'application-uuid', mobile: '+94771234567', status: 'draft', current_step: 1 } } }),
            response('Existing Driver Login', 200, { status: 'success', message: 'Login successful', data: { flow: 'login', user: {}, driver: {}, device: {}, token: { access_token: 'token', refresh_token: 'token', expires_in: 3600 }, current_assignment: null, trip_phase: null } }),
        ]),
        requestItem('Get Onboarding Progress', 'GET', '{{base_url}}/api/driver/onboarding', undefined, 'Resume the stepper. Use onboarding_token as the Bearer token.', [response('OK', 200, { status: 'success', data: { id: 'application-uuid', mobile: '+94771234567', status: 'draft', current_step: 1, payload: {}, documents: [], review_issues: null, editable_fields: null, review_message: null } })]),
        requestItem('Save Identity Step', 'PATCH', '{{base_url}}/api/driver/onboarding/steps/1', { first_name: 'Nimal', last_name: 'Perera', email: 'nimal@example.com', nic: '901234567V' }, 'Save identity. NIC accepts the Sri Lankan 9-digit plus V/X or 12-digit format; date of birth is derived by the server. In changes_requested status, only reviewer-listed fields are writable.', [response('OK', 200, { status: 'success', data: { id: 'application-uuid', status: 'draft', current_step: 2, payload: { identity: { first_name: 'Nimal', last_name: 'Perera', email: 'nimal@example.com', nic: '901234567V', dob: '1990-05-02' } } } })]),
        requestItem('Save Address Step', 'PATCH', '{{base_url}}/api/driver/onboarding/steps/3', { address: '10 Main Street', country_id: 'country-uuid', state_id: 'state-uuid', city: 'Colombo', postal_code: '00100' }, 'Use UUID IDs returned by the country/state lookups. The state must belong to the selected country.', [response('OK', 200, { status: 'success', data: { id: 'application-uuid', status: 'draft', current_step: 4 } })]),
        requestItem('Save Vehicle Step', 'PATCH', '{{base_url}}/api/driver/onboarding/steps/4', { make_id: 'make-uuid', model_id: 'model-uuid', model_year: 2024, color: 'White', registration_year: 2024, license_plate: 'CAB-1234', is_owner: true }, 'Send existing make_id/model_id, or other_make/other_model when an option is missing. Staff assign the canonical make and model before approval.', [response('OK', 200, { status: 'success', data: { id: 'application-uuid', status: 'draft', current_step: 5, payload: { vehicle: { make_id: 'make-uuid', model_id: 'model-uuid' } } } })]),
        requestItem('Submit for Review', 'POST', '{{base_url}}/api/driver/onboarding/submit', {}, 'Submit only after every required step and document is present.', [response('OK', 200, { status: 'success', message: 'Application submitted for review.', data: { id: 'application-uuid', status: 'submitted', current_step: 5 } })]),
    ],
};
onboardingFolder.item = [
    requestItem('List Countries', 'GET', '{{base_url}}/api/driver/onboarding/countries', undefined, 'Public country options for the address step. Display name and save id.', [response('OK', 200, [{ id: 'country-uuid', name: 'Sri Lanka', code: 'LK' }])]),
    requestItem('List States by Country', 'GET', '{{base_url}}/api/driver/onboarding/countries/{{country_id}}/states', undefined, 'Public state options for the selected country. Display name and save id.', [response('OK', 200, [{ id: 'state-uuid', country_id: 'country-uuid', name: 'Western Province' }])]),
    requestItem('List Vehicle Makes', 'GET', '{{base_url}}/api/driver/onboarding/makes', undefined, 'Public vehicle make options. Display name and save id as make_id.', [response('OK', 200, { status: 'success', data: [{ id: 'make-uuid', name: 'Toyota' }] })]),
    requestItem('List Models by Make', 'GET', '{{base_url}}/api/driver/onboarding/makes/{{make_id}}/models', undefined, 'Public models for the selected make. Display name and save id as model_id.', [response('OK', 200, { status: 'success', data: [{ id: 'model-uuid', make_id: 'make-uuid', name: 'Axio' }] })]),
    ...onboardingFolder.item.filter((item) => !item.name.includes('Mobile OTP')),
];
for (const item of onboardingFolder.item) item.request.auth = item.name.startsWith('List ') ? { type: 'noauth' } : bearer;
onboardingFolder.item.splice(6, 0, {
    name: 'Upload Onboarding Document',
    request: { auth: bearer, method: 'POST', header: [{ key: 'Accept', value: 'application/json' }],
        body: { mode: 'formdata', formdata: [
            { key: 'document_type', value: 'driver_license_front', type: 'text' },
            { key: 'document_number', value: 'B1234567', type: 'text' },
            { key: 'expiry_date', value: '2030-12-31', type: 'text' },
            { key: 'reminder_days', value: '30', type: 'text' },
            { key: 'file', type: 'file', src: [] },
        ] }, url: { raw: '{{base_url}}/api/driver/onboarding/documents', host: ['{{base_url}}'], path: ['api', 'driver', 'onboarding', 'documents'] },
        description: 'Allowed types: driver_photo, driver_license_front/back, nic_front/back, vehicle_insurance, vehicle_revenue_license, vehicle_registration. JPG, PNG or PDF; maximum 10 MB.' }, response: [response('Created', 201, { status: 'success', data: { id: 'document-uuid', document_type: 'driver_license_front', document_number: 'B1234567', expiry_date: '2030-12-31', reminder_days: 30, status: 'pending' } })],
});
collection.item = collection.item.filter((folder) => folder.name !== 'Driver Onboarding');
collection.item.splice(1, 0, onboardingFolder);

openapi.components.schemas.DriverOnboardingApplication = {
    type: 'object', required: ['id', 'mobile', 'status', 'current_step'],
    additionalProperties: true,
    properties: {
        id: { type: 'string', format: 'uuid' }, mobile: { type: 'string' }, current_step: { type: 'integer', minimum: 1, maximum: 6 },
        status: { type: 'string', enum: ['draft', 'submitted', 'changes_requested', 'approved', 'rejected'] },
        payload: { type: 'object', additionalProperties: true }, documents: { type: 'array', items: { type: 'object', additionalProperties: true } },
        review_issues: { type: 'array', nullable: true, items: { type: 'object', required: ['field', 'message'], properties: { field: { type: 'string' }, message: { type: 'string' } } } },
        editable_fields: { type: 'array', nullable: true, items: { type: 'string' } }, review_message: { type: 'string', nullable: true },
    },
};
const onboardingEnvelope = { type: 'object', required: ['status', 'data'], properties: { status: { type: 'string', example: 'success' }, data: { $ref: '#/components/schemas/DriverOnboardingApplication' } } };
const onboardingResponse = { 200: jsonResponse('Onboarding application', onboardingEnvelope), 401: errorResponse('Missing or invalid onboarding token'), 404: errorResponse('Onboarding application not found'), 422: errorResponse('Validation error') };
const deviceProperties = {
    device_uuid: { type: 'string', maxLength: 255, nullable: true }, device_fingerprint: { type: 'string', maxLength: 500, nullable: true },
    device_name: { type: 'string', maxLength: 255, nullable: true }, device_model: { type: 'string', maxLength: 255, nullable: true },
    device_manufacturer: { type: 'string', maxLength: 255, nullable: true }, platform: { type: 'string', enum: ['ios', 'android'], nullable: true },
    os_version: { type: 'string', maxLength: 50, nullable: true }, app_version: { type: 'string', maxLength: 50, nullable: true },
    app_build: { type: 'string', maxLength: 50, nullable: true }, push_token: { type: 'string', maxLength: 500, nullable: true },
    push_provider: { type: 'string', enum: ['fcm', 'apns'], nullable: true }, locale: { type: 'string', maxLength: 10, nullable: true },
    timezone: { type: 'string', maxLength: 50, nullable: true },
};
openapi.paths['/api/driver/onboarding/request-otp'] = { post: { tags: ['Driver Onboarding'], summary: 'Send mobile verification OTP', security: [], requestBody: { required: true, content: { 'application/json': { schema: { type: 'object', required: ['mobile'], properties: { mobile: { type: 'string', maxLength: 30, example: '+94771234567' } } } } } }, responses: { 200: jsonResponse('OTP queued', { type: 'object', required: ['status', 'message', 'data'], properties: { status: { type: 'string' }, message: { type: 'string' }, data: { type: 'object', required: ['expires_in'], properties: { expires_in: { type: 'integer', example: 600 } } } } }, { status: 'success', message: 'OTP sent.', data: { expires_in: 600 } }), 422: errorResponse('Invalid mobile'), 429: errorResponse('Rate limited') } } };
openapi.paths['/api/driver/onboarding/verify-otp'] = { post: { tags: ['Driver Onboarding'], summary: 'Verify OTP, then log in or start onboarding', description: 'Existing drivers receive HTTP 200 with the normal authenticated login payload. New drivers receive HTTP 201 with an onboarding token.', security: [], requestBody: { required: true, content: { 'application/json': { schema: { type: 'object', required: ['mobile', 'otp'], properties: { mobile: { type: 'string', maxLength: 30 }, otp: { type: 'string', pattern: '^\\d{6}$' }, ...deviceProperties } } } } }, responses: {
    200: jsonResponse('Existing driver login', { type: 'object', required: ['status', 'data'], properties: { status: { type: 'string' }, message: { type: 'string' }, data: { type: 'object', required: ['flow', 'user', 'driver', 'device', 'token'], properties: { flow: { type: 'string', enum: ['login'] }, user: { type: 'object', additionalProperties: true }, driver: { type: 'object', additionalProperties: true }, device: { type: 'object', additionalProperties: true }, token: { type: 'object', additionalProperties: true }, current_assignment: { type: 'object', nullable: true, additionalProperties: true }, trip_phase: { type: 'string', nullable: true } } } } }),
    201: jsonResponse('New driver onboarding created', { type: 'object', required: ['status', 'data'], properties: { status: { type: 'string' }, data: { type: 'object', required: ['flow', 'onboarding_token', 'application'], properties: { flow: { type: 'string', enum: ['registration'] }, onboarding_token: { type: 'string' }, application: { $ref: '#/components/schemas/DriverOnboardingApplication' } } } } }),
    422: errorResponse('Invalid or expired OTP'), 429: errorResponse('Rate limited'),
} } };
openapi.paths['/api/driver/onboarding'] = { get: { tags: ['Driver Onboarding'], summary: 'Resume onboarding', description: 'Use onboarding_token as Bearer token, not the normal driver access token.', security: protectedSecurity, responses: onboardingResponse } };
const identityStep = { type: 'object', additionalProperties: false, required: ['first_name', 'last_name', 'email', 'nic'], properties: { first_name: { type: 'string', maxLength: 100 }, last_name: { type: 'string', maxLength: 100 }, email: { type: 'string', format: 'email', maxLength: 255 }, nic: { type: 'string', pattern: '^(?:\\d{9}[vVxX]|\\d{12})$', description: 'Sri Lankan NIC. The server derives payload.identity.dob from this value.' } } };
const addressStep = { type: 'object', additionalProperties: false, required: ['address', 'country_id', 'state_id', 'city'], properties: { address: { type: 'string', maxLength: 500 }, country_id: { type: 'string', format: 'uuid' }, state_id: { type: 'string', format: 'uuid' }, city: { type: 'string', maxLength: 100 }, postal_code: { type: 'string', maxLength: 20, nullable: true } } };
const vehicleStep = { type: 'object', additionalProperties: false, required: ['model_year', 'color', 'registration_year', 'license_plate', 'is_owner'], allOf: [{ anyOf: [{ required: ['make_id'] }, { required: ['other_make'] }] }, { anyOf: [{ required: ['model_id'] }, { required: ['other_model'] }] }], properties: { make_id: { type: 'string', format: 'uuid', nullable: true, description: 'Existing make ID; required unless other_make is sent.' }, other_make: { type: 'string', maxLength: 255, nullable: true, description: 'Driver-entered make when it is not listed.' }, model_id: { type: 'string', format: 'uuid', nullable: true, description: 'Existing model ID belonging to make_id; required unless other_model is sent.' }, other_model: { type: 'string', maxLength: 255, nullable: true, description: 'Driver-entered model when it is not listed.' }, model_year: { type: 'integer', minimum: 1950 }, color: { type: 'string', maxLength: 50 }, registration_year: { type: 'integer', minimum: 1950 }, license_plate: { type: 'string', maxLength: 30 }, is_owner: { type: 'boolean' } } };
openapi.paths['/api/driver/onboarding/steps/{step}'] = { patch: { tags: ['Driver Onboarding'], summary: 'Save identity, address, or vehicle onboarding step', description: 'Payload must match the selected step: 1 identity, 3 address, 4 vehicle. Vehicle make/model may use existing IDs or other_make/other_model for staff classification.', security: protectedSecurity, parameters: [{ name: 'step', in: 'path', required: true, schema: { type: 'integer', enum: [1, 3, 4] } }], requestBody: { required: true, content: { 'application/json': { schema: { oneOf: [identityStep, addressStep, vehicleStep] }, examples: { identity: { value: { first_name: 'Nimal', last_name: 'Perera', email: 'nimal@example.com', nic: '901234567V' } }, address: { value: { address: '10 Main Street', country_id: 'country-uuid', state_id: 'state-uuid', city: 'Colombo', postal_code: '00100' } }, vehicle: { value: { make_id: 'make-uuid', model_id: 'model-uuid', model_year: 2024, color: 'White', registration_year: 2024, license_plate: 'CAB-1234', is_owner: true } }, otherVehicle: { value: { other_make: 'New Make', other_model: 'New Model', model_year: 2024, color: 'White', registration_year: 2024, license_plate: 'CAB-1234', is_owner: true } } } } } }, responses: { ...onboardingResponse, 403: errorResponse('Only reviewer-listed fields may be changed'), 409: errorResponse('Application cannot be edited') } } };
openapi.paths['/api/driver/onboarding/documents'] = { post: { tags: ['Driver Onboarding'], summary: 'Upload or replace one onboarding document', security: protectedSecurity, requestBody: { required: true, content: { 'multipart/form-data': { schema: { type: 'object', required: ['document_type', 'file'], properties: { document_type: { type: 'string', enum: ['driver_photo', 'driver_license_front', 'driver_license_back', 'nic_front', 'nic_back', 'vehicle_insurance', 'vehicle_revenue_license', 'vehicle_registration'] }, document_number: { type: 'string', maxLength: 100, nullable: true }, expiry_date: { type: 'string', format: 'date', nullable: true, description: 'Required for driver_license_front, vehicle_insurance, and vehicle_revenue_license.' }, reminder_days: { type: 'integer', minimum: 1, maximum: 365, nullable: true }, file: { type: 'string', format: 'binary', description: 'JPG, PNG, or PDF; maximum 10 MB.' } } } } } }, responses: { 201: jsonResponse('Document uploaded', { type: 'object', required: ['status', 'data'], properties: { status: { type: 'string' }, data: { type: 'object', additionalProperties: true } } }), 401: errorResponse('Missing or invalid onboarding token'), 403: errorResponse('Field was not opened for correction'), 409: errorResponse('Application cannot be edited'), 422: errorResponse('Validation error') } } };
openapi.paths['/api/driver/onboarding/submit'] = { post: { tags: ['Driver Onboarding'], summary: 'Submit completed onboarding for review', security: protectedSecurity, responses: onboardingResponse } };
delete openapi.paths['/api/driver/onboarding/request-otp'];
delete openapi.paths['/api/driver/onboarding/verify-otp'];
const countrySchema = { type: 'object', required: ['id', 'name'], properties: { id: { type: 'string', format: 'uuid' }, name: { type: 'string' }, code: { type: 'string', nullable: true } } };
const stateSchema = { type: 'object', required: ['id', 'country_id', 'name'], properties: { id: { type: 'string', format: 'uuid' }, country_id: { type: 'string', format: 'uuid' }, name: { type: 'string' } } };
openapi.paths['/api/driver/onboarding/countries'] = { get: { tags: ['Driver Onboarding'], summary: 'List countries for the address step', security: [], responses: { 200: jsonResponse('Country list', { type: 'array', items: countrySchema }, [{ id: 'country-uuid', name: 'Sri Lanka', code: 'LK' }]) } } };
openapi.paths['/api/driver/onboarding/countries/{country_id}/states'] = { get: { tags: ['Driver Onboarding'], summary: 'List states for the selected country', security: [], parameters: [{ name: 'country_id', in: 'path', required: true, schema: { type: 'string', format: 'uuid' } }], responses: { 200: jsonResponse('State list', { type: 'array', items: stateSchema }, [{ id: 'state-uuid', country_id: 'country-uuid', name: 'Western Province' }]) } } };
const makeSchema = { type: 'object', required: ['id', 'name'], properties: { id: { type: 'string', format: 'uuid' }, name: { type: 'string' } } };
const modelSchema = { type: 'object', required: ['id', 'make_id', 'name'], properties: { id: { type: 'string', format: 'uuid' }, make_id: { type: 'string', format: 'uuid' }, name: { type: 'string' } } };
const lookupEnvelope = (items) => ({ type: 'object', required: ['status', 'data'], properties: { status: { type: 'string', enum: ['success'] }, data: { type: 'array', items } } });
openapi.paths['/api/driver/onboarding/makes'] = { get: { tags: ['Driver Onboarding'], summary: 'List vehicle makes for registration', security: [], responses: { 200: jsonResponse('Vehicle makes', lookupEnvelope(makeSchema), { status: 'success', data: [{ id: 'make-uuid', name: 'Toyota' }] }) } } };
openapi.paths['/api/driver/onboarding/makes/{make_id}/models'] = { get: { tags: ['Driver Onboarding'], summary: 'List vehicle models for the selected make', security: [], parameters: [{ name: 'make_id', in: 'path', required: true, schema: { type: 'string', format: 'uuid' } }], responses: { 200: jsonResponse('Vehicle models', lookupEnvelope(modelSchema), { status: 'success', data: [{ id: 'model-uuid', make_id: 'make-uuid', name: 'Axio' }] }), 404: errorResponse('Vehicle make not found') } } };

for (const stalePath of ['/api/driver/onboarding/steps/1', '/api/driver/onboarding/steps/3', '/api/driver/onboarding/steps/4']) delete openapi.paths[stalePath];

write(collectionPath, collection);
write(openApiPath, openapi);
