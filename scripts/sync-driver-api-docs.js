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
    [],
);
versionGet.request.auth = { type: 'noauth' };
upsertAfter('App Settings', 'Version Check', versionGet);

const forgotPassword = requestItem('Forgot Password', 'POST', '{{base_url}}/api/driver/auth/forgot-password',
    { email: 'driver@example.com' }, 'Emails a six-digit password reset OTP. The response never reveals whether the account exists.', []);
forgotPassword.request.auth = { type: 'noauth' };
upsertAfter('Authentication', 'Login', forgotPassword);
const resetPassword = requestItem('Reset Password', 'POST', '{{base_url}}/api/driver/auth/reset-password',
    { email: 'driver@example.com', otp: '123456', password: 'NewPassword1!', password_confirmation: 'NewPassword1!' },
    'Resets an eligible driver password, clears lockout state, and revokes all sessions.', []);
resetPassword.request.auth = { type: 'noauth' };
upsertAfter('Authentication', 'Forgot Password', resetPassword);
upsertAfter('Authentication', 'Profile', requestItem('Change Password', 'POST', '{{base_url}}/api/driver/auth/change-password',
    { current_password: 'CurrentPassword1!', new_password: 'NewPassword1!', new_password_confirmation: 'NewPassword1!' },
    'Changes the authenticated driver password and revokes every existing session/device.', []));

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

openapi.info.version = '2.5.0';
openapi.info.description = 'Complete canonical Driver Mobile API contract. Assignment projections expose server-owned service capabilities and traveler/contact identity. Pricing is visible only when the driver must collect payment. Complete-trip calculates and stores the final amount first; cash collection uses the separate collect-payment endpoint only when `payment.collection_required` is true.';

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

const passwordSchema = { type: 'string', format: 'password', minLength: 8 };
openapi.paths['/api/driver/auth/forgot-password'] = {
    post: { tags: ['Authentication'], summary: 'Request driver password reset', security: [],
        requestBody: { required: true, content: { 'application/json': { schema: { type: 'object', required: ['email'], properties: { email: { type: 'string', format: 'email' } } } } } },
        responses: { 200: { description: 'Enumeration-safe acknowledgement' }, 422: { description: 'Invalid email format', content: { 'application/json': { schema: errorSchema } } }, 429: { description: 'Rate limited' } } },
};
openapi.paths['/api/driver/auth/reset-password'] = {
    post: { tags: ['Authentication'], summary: 'Reset driver password', security: [],
        requestBody: { required: true, content: { 'application/json': { schema: { type: 'object', required: ['email', 'otp', 'password', 'password_confirmation'], properties: { email: { type: 'string', format: 'email' }, otp: { type: 'string', pattern: '^\\d{6}$', example: '123456' }, password: passwordSchema, password_confirmation: passwordSchema } } } } },
        responses: { 200: { description: 'Password reset; all sessions revoked' }, 422: { description: 'Invalid or expired OTP', content: { 'application/json': { schema: errorSchema } } }, 429: { description: 'Rate limited' } } },
};
openapi.paths['/api/driver/auth/change-password'] = {
    post: { tags: ['Authentication'], summary: 'Change driver password', security: protectedSecurity,
        requestBody: { required: true, content: { 'application/json': { schema: { type: 'object', required: ['current_password', 'new_password', 'new_password_confirmation'], properties: { current_password: passwordSchema, new_password: passwordSchema, new_password_confirmation: passwordSchema } } } } },
        responses: { 200: { description: 'Password changed; reauthentication required' }, 401: { description: 'Unauthenticated', content: { 'application/json': { schema: errorSchema } } }, 422: { description: 'Current password or validation failure', content: { 'application/json': { schema: errorSchema } } }, 429: { description: 'Rate limited' } } },
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

write(collectionPath, collection);
write(openApiPath, openapi);
