--
-- PostgreSQL database dump
--

-- Dumped from database version 17.5
-- Dumped by pg_dump version 17.5

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: activity_log; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.activity_log (
    id bigint NOT NULL,
    log_name character varying(255),
    description text NOT NULL,
    subject_type character varying(255),
    subject_id character varying(255),
    causer_type character varying(255),
    causer_id character varying(255),
    properties json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    event character varying(255),
    batch_uuid uuid,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: activity_log_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.activity_log_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: activity_log_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.activity_log_id_seq OWNED BY public.activity_log.id;


--
-- Name: agent_api_sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agent_api_sessions (
    id uuid NOT NULL,
    agent_id uuid,
    agent_api_id uuid,
    last_access timestamp(0) without time zone,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: agent_apis; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agent_apis (
    id uuid NOT NULL,
    title character varying(255),
    description text,
    api_key character varying(255) NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: agent_commissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agent_commissions (
    id uuid NOT NULL,
    agent_id uuid NOT NULL,
    booking_id uuid NOT NULL,
    amount numeric(12,2) NOT NULL,
    paid boolean DEFAULT false NOT NULL,
    paid_at date,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: agents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agents (
    id uuid NOT NULL,
    user_id uuid NOT NULL,
    code character varying(255),
    commission_rate numeric(5,2) NOT NULL,
    branding_config json,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: audit_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.audit_logs (
    id uuid NOT NULL,
    user_id uuid,
    action character varying(255) NOT NULL,
    entity character varying(255) NOT NULL,
    entity_id uuid,
    "timestamp" timestamp(0) without time zone NOT NULL,
    details json,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: badges; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.badges (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    description character varying(255),
    icon character varying(255),
    level smallint DEFAULT '1'::smallint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: badges_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.badges_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: badges_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.badges_id_seq OWNED BY public.badges.id;


--
-- Name: billing_addresses; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.billing_addresses (
    id uuid NOT NULL,
    user_id uuid NOT NULL,
    company_name character varying(255),
    contact_name character varying(255),
    phone character varying(255),
    address_line1 character varying(255) NOT NULL,
    address_line2 character varying(255),
    city character varying(255) NOT NULL,
    state character varying(255),
    postal_code character varying(255) NOT NULL,
    country character(2) NOT NULL,
    latitude numeric(10,7),
    longitude numeric(10,7),
    is_default boolean DEFAULT false NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: booking_addons; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.booking_addons (
    id uuid NOT NULL,
    booking_id uuid NOT NULL,
    addon_id uuid NOT NULL,
    qty integer DEFAULT 1 NOT NULL,
    rate numeric(12,2),
    amount numeric(12,2),
    is_insurance boolean DEFAULT false NOT NULL,
    is_milage boolean DEFAULT false NOT NULL,
    label character varying(255),
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: booking_approvals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.booking_approvals (
    id uuid NOT NULL,
    booking_id uuid NOT NULL,
    requested_by uuid NOT NULL,
    approver_id uuid,
    manager_id uuid,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    priority character varying(255) DEFAULT 'normal'::character varying NOT NULL,
    override_reasons json,
    justification text,
    comments text,
    approved_at timestamp(0) without time zone,
    rejected_at timestamp(0) without time zone,
    auto_approved boolean DEFAULT false NOT NULL,
    approval_level integer DEFAULT 1 NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    processed_by uuid,
    approval_type character varying(255) DEFAULT 'manager'::character varying NOT NULL,
    notes text,
    conditions json,
    requested_at timestamp(0) without time zone,
    processed_at timestamp(0) without time zone,
    CONSTRAINT booking_approvals_priority_check CHECK (((priority)::text = ANY ((ARRAY['normal'::character varying, 'high'::character varying, 'urgent'::character varying])::text[]))),
    CONSTRAINT booking_approvals_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'approved'::character varying, 'rejected'::character varying, 'escalated'::character varying])::text[])))
);


--
-- Name: booking_channels; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.booking_channels (
    id uuid NOT NULL,
    name character varying(255),
    description text,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: booking_discounts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.booking_discounts (
    id uuid NOT NULL,
    booking_id uuid NOT NULL,
    discount_name character varying(255) NOT NULL,
    description text,
    type character varying(255) NOT NULL,
    value numeric(10,2) NOT NULL,
    discount_amount numeric(10,2) NOT NULL,
    original_amount numeric(10,2) NOT NULL,
    final_amount numeric(10,2) NOT NULL,
    loyalty_points_used integer,
    points_to_amount_rate numeric(8,4),
    application_method character varying(255) NOT NULL,
    discount_code character varying(255),
    applied_by uuid NOT NULL,
    applied_at timestamp(0) without time zone NOT NULL,
    requires_approval boolean DEFAULT false NOT NULL,
    approval_status character varying(255) DEFAULT 'not_required'::character varying NOT NULL,
    approved_by uuid,
    approved_at timestamp(0) without time zone,
    approval_notes text,
    valid_from timestamp(0) without time zone,
    valid_until timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL,
    conditions_met json,
    calculation_details json,
    internal_notes text,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT booking_discounts_application_method_check CHECK (((application_method)::text = ANY ((ARRAY['automatic'::character varying, 'manual'::character varying, 'code'::character varying, 'loyalty_redemption'::character varying])::text[]))),
    CONSTRAINT booking_discounts_approval_status_check CHECK (((approval_status)::text = ANY ((ARRAY['pending'::character varying, 'approved'::character varying, 'rejected'::character varying, 'not_required'::character varying])::text[]))),
    CONSTRAINT booking_discounts_type_check CHECK (((type)::text = ANY ((ARRAY['percentage'::character varying, 'fixed_amount'::character varying, 'loyalty_points'::character varying])::text[])))
);


--
-- Name: booking_dispatches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.booking_dispatches (
    id uuid NOT NULL,
    booking_id uuid NOT NULL,
    vehicle_id uuid NOT NULL,
    driver_id uuid,
    dispatch_status character varying(255) DEFAULT 'not_dispatched'::character varying NOT NULL,
    dispatched_at timestamp(0) without time zone,
    dispatched_by uuid,
    expected_return_at timestamp(0) without time zone,
    actual_return_at timestamp(0) without time zone,
    returned_by uuid,
    dispatch_notes text,
    return_notes text,
    fuel_level_out numeric(3,1),
    fuel_level_in numeric(3,1),
    mileage_out integer,
    mileage_in integer,
    vehicle_condition_out json,
    vehicle_condition_in json,
    damages_reported json,
    additional_charges json,
    late_return_fee numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    documents_generated json,
    agreements_signed boolean DEFAULT false NOT NULL,
    is_self_driven boolean DEFAULT false NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    deleted_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT booking_dispatches_dispatch_status_check CHECK (((dispatch_status)::text = ANY ((ARRAY['not_dispatched'::character varying, 'ready_for_dispatch'::character varying, 'dispatched'::character varying, 'in_progress'::character varying, 'returned'::character varying])::text[])))
);


--
-- Name: booking_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.booking_items (
    id bigint NOT NULL,
    booking_id uuid NOT NULL,
    vehicle_group_id uuid NOT NULL,
    vehicle_id uuid,
    driver_id uuid,
    quantity integer DEFAULT 1 NOT NULL,
    unit_price numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    total_price numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    pricing_breakdown json,
    addons json,
    customizations json,
    discounts json,
    from_date timestamp(0) without time zone NOT NULL,
    to_date timestamp(0) without time zone NOT NULL,
    duration_days integer DEFAULT 0 NOT NULL,
    duration_hours integer DEFAULT 0 NOT NULL,
    currency character varying(3) DEFAULT 'LKR'::character varying NOT NULL,
    exchange_rate numeric(10,6) DEFAULT '1'::numeric NOT NULL,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    requires_approval boolean DEFAULT false NOT NULL,
    approved_at timestamp(0) without time zone,
    approved_by uuid,
    item_type character varying(255) DEFAULT 'vehicle_group'::character varying NOT NULL,
    notes text,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT booking_items_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'confirmed'::character varying, 'cancelled'::character varying, 'completed'::character varying])::text[])))
);


--
-- Name: booking_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.booking_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: booking_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.booking_items_id_seq OWNED BY public.booking_items.id;


--
-- Name: booking_pricings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.booking_pricings (
    id uuid NOT NULL,
    booking_id uuid NOT NULL,
    slab_definition_id uuid NOT NULL,
    vehicle_group_pricing_id uuid NOT NULL,
    calculated_amount numeric(15,2) NOT NULL,
    rate_type character varying(255) NOT NULL,
    applied_rate numeric(15,2) NOT NULL,
    hours_calculated integer NOT NULL,
    days_calculated integer NOT NULL,
    minimum_charge_applied boolean DEFAULT false NOT NULL,
    includes_fuel boolean DEFAULT false NOT NULL,
    includes_driver boolean DEFAULT false NOT NULL,
    created_by uuid,
    updated_by uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT booking_pricings_rate_type_check CHECK (((rate_type)::text = ANY ((ARRAY['per_hour'::character varying, 'per_day'::character varying, 'flat_rate'::character varying])::text[])))
);


--
-- Name: booking_qc_repair_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.booking_qc_repair_items (
    id uuid NOT NULL,
    qc_id uuid NOT NULL,
    item_type character varying(255) NOT NULL,
    description text NOT NULL,
    location character varying(255),
    severity character varying(255) DEFAULT 'minor'::character varying NOT NULL,
    estimated_cost numeric(10,2),
    actual_cost numeric(10,2),
    repair_status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    repaired_at timestamp(0) without time zone,
    repaired_by uuid,
    repair_notes text,
    photos json,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT booking_qc_repair_items_repair_status_check CHECK (((repair_status)::text = ANY ((ARRAY['pending'::character varying, 'in_progress'::character varying, 'completed'::character varying, 'cancelled'::character varying])::text[]))),
    CONSTRAINT booking_qc_repair_items_severity_check CHECK (((severity)::text = ANY ((ARRAY['minor'::character varying, 'moderate'::character varying, 'major'::character varying, 'critical'::character varying])::text[])))
);


--
-- Name: booking_qcs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.booking_qcs (
    id uuid NOT NULL,
    booking_id uuid NOT NULL,
    vehicle_id uuid NOT NULL,
    dispatch_id uuid,
    qc_status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    inspector_id uuid,
    inspection_started_at timestamp(0) without time zone,
    inspection_completed_at timestamp(0) without time zone,
    interior_condition json,
    exterior_condition json,
    mechanical_condition json,
    cleanliness_rating integer,
    fuel_level numeric(3,1),
    mileage integer,
    damages_found json,
    issues_reported json,
    repair_required boolean DEFAULT false NOT NULL,
    estimated_repair_cost numeric(10,2),
    repair_notes text,
    qc_notes text,
    photos json,
    passed_inspection boolean DEFAULT false NOT NULL,
    requires_maintenance boolean DEFAULT false NOT NULL,
    next_maintenance_due date,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT booking_qcs_qc_status_check CHECK (((qc_status)::text = ANY ((ARRAY['pending'::character varying, 'in_progress'::character varying, 'completed'::character varying, 'issues_found'::character varying, 'repair_required'::character varying])::text[])))
);


--
-- Name: booking_searches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.booking_searches (
    id uuid NOT NULL,
    session_id character varying(255) NOT NULL,
    service_type character varying(255) NOT NULL,
    search_data json NOT NULL,
    pickup_date timestamp(0) without time zone NOT NULL,
    dropoff_date timestamp(0) without time zone,
    pickup_location character varying(255),
    dropoff_location character varying(255),
    estimated_distance numeric(10,2),
    duration_hours integer,
    duration_days integer,
    customer_id uuid,
    ip_address character varying(45),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    view_count integer DEFAULT 0 NOT NULL
);


--
-- Name: booking_status_histories; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.booking_status_histories (
    id bigint NOT NULL,
    booking_id uuid NOT NULL,
    old_status character varying(255) NOT NULL,
    new_status character varying(255) NOT NULL,
    reason character varying(255),
    notes text,
    changed_by uuid,
    changed_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: booking_status_histories_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.booking_status_histories_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: booking_status_histories_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.booking_status_histories_id_seq OWNED BY public.booking_status_histories.id;


--
-- Name: booking_statuses; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.booking_statuses (
    id uuid NOT NULL,
    booking_id uuid,
    old_status character varying(255),
    new_status character varying(255),
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: booking_variable_customizations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.booking_variable_customizations (
    id uuid NOT NULL,
    booking_id uuid,
    session_id character varying(255),
    variable_name character varying(255) NOT NULL,
    variable_type character varying(255) NOT NULL,
    original_value numeric(15,2) NOT NULL,
    custom_value numeric(15,2) NOT NULL,
    customization_reason text,
    context character varying(255) NOT NULL,
    metadata json,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    vehicle_group_id uuid
);


--
-- Name: bookings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.bookings (
    id uuid NOT NULL,
    customer_id uuid NOT NULL,
    invoice_number character varying(255),
    log_code character varying(255),
    service_type_id uuid NOT NULL,
    vehicle_group_id uuid NOT NULL,
    vehicle_id uuid,
    driver_id uuid,
    vip_id uuid,
    booking_date timestamp(0) without time zone,
    from_date timestamp(0) without time zone,
    to_date timestamp(0) without time zone,
    from_time time(0) without time zone,
    to_time time(0) without time zone,
    pickup_location jsonb,
    dropoff_location jsonb,
    total_estimated numeric(12,2),
    total_actual numeric(12,2),
    created_from character varying(255) DEFAULT 'web'::character varying NOT NULL,
    confirmed boolean DEFAULT false NOT NULL,
    third_party_ref character varying(255),
    pickup_latitude numeric(10,7),
    pickup_longitude numeric(10,7),
    pickup_landmark character varying(255),
    dropoff_latitude numeric(10,7),
    dropoff_longitude numeric(10,7),
    dropoff_landmark character varying(255),
    is_self_driven boolean DEFAULT false NOT NULL,
    passenger_count integer DEFAULT 1 NOT NULL,
    luggage_count integer,
    special_requirements text,
    base_amount numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    driver_cost numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    distance_cost numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    addons_cost numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    discount_amount numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    tax_amount numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    currency character varying(3) DEFAULT 'LKR'::character varying NOT NULL,
    payment_method character varying(255),
    payment_status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    payment_reference character varying(255),
    is_corporate_booking boolean DEFAULT false NOT NULL,
    corporate_account_id uuid,
    cost_center character varying(255),
    project_code character varying(255),
    employee_id character varying(255),
    review_notes jsonb,
    is_recurring boolean DEFAULT false NOT NULL,
    recurrence_pattern character varying(255),
    recurrence_end_date date,
    recurrence_days json,
    requires_approval boolean DEFAULT false NOT NULL,
    approval_status character varying(255) DEFAULT 'not_required'::character varying NOT NULL,
    approval_requested_by uuid,
    approval_requested_at timestamp(0) without time zone,
    approval_justification text,
    approval_priority character varying(255) DEFAULT 'normal'::character varying NOT NULL,
    created_by_user_id uuid,
    booking_source character varying(50) DEFAULT 'internal'::character varying NOT NULL,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    override_reasons jsonb,
    has_overrides boolean DEFAULT false NOT NULL,
    concurrent_assignments json,
    workflow_step character varying(255),
    workflow_data jsonb,
    estimated_distance numeric(8,2),
    estimated_duration integer,
    actual_distance numeric(8,2),
    actual_duration integer,
    toll_charges_included boolean DEFAULT false NOT NULL,
    fuel_charges_included boolean DEFAULT true NOT NULL,
    parking_charges_included boolean DEFAULT false NOT NULL,
    emergency_contact_name character varying(255),
    emergency_contact_phone character varying(255),
    emergency_contact_relationship character varying(255),
    insurance_type character varying(255),
    safety_features_required json,
    notification_sms boolean DEFAULT true NOT NULL,
    notification_email boolean DEFAULT true NOT NULL,
    notification_whatsapp boolean DEFAULT false NOT NULL,
    notification_push boolean DEFAULT true NOT NULL,
    booking_number character varying(255),
    confirmation_number character varying(255),
    booked_at timestamp(0) without time zone,
    confirmed_at timestamp(0) without time zone,
    completed_at timestamp(0) without time zone,
    cancelled_at timestamp(0) without time zone,
    trip_status character varying(255) DEFAULT 'not_started'::character varying NOT NULL,
    trip_started_at timestamp(0) without time zone,
    trip_ended_at timestamp(0) without time zone,
    current_latitude numeric(10,7),
    current_longitude numeric(10,7),
    location_updated_at timestamp(0) without time zone,
    customer_rating integer,
    customer_feedback text,
    driver_rating integer,
    driver_feedback text,
    base_price_override numeric(12,2),
    base_price_override_reason text,
    base_price_edited_by uuid,
    base_price_edited_at timestamp(0) without time zone,
    addon_overrides json,
    discounts jsonb,
    approval_by uuid,
    approval_at timestamp(0) without time zone,
    approval_note text,
    original_totals json,
    edited_totals json,
    gamify_points_earned numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    gamify_discount_applied numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    gamify_details jsonb,
    pricing_snapshot jsonb,
    duration_metrics jsonb,
    distance_metrics jsonb,
    is_active boolean DEFAULT true NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT bookings_approval_priority_check CHECK (((approval_priority)::text = ANY ((ARRAY['normal'::character varying, 'high'::character varying, 'urgent'::character varying])::text[]))),
    CONSTRAINT bookings_approval_status_check CHECK (((approval_status)::text = ANY ((ARRAY['not_required'::character varying, 'pending'::character varying, 'approved'::character varying, 'rejected'::character varying])::text[]))),
    CONSTRAINT bookings_created_from_check CHECK (((created_from)::text = ANY ((ARRAY['web'::character varying, 'internal'::character varying, 'api'::character varying, 'agent'::character varying])::text[]))),
    CONSTRAINT bookings_insurance_type_check CHECK (((insurance_type)::text = ANY ((ARRAY['basic'::character varying, 'comprehensive'::character varying, 'premium'::character varying])::text[]))),
    CONSTRAINT bookings_payment_method_check CHECK (((payment_method)::text = ANY ((ARRAY['cash'::character varying, 'card'::character varying, 'wallet'::character varying, 'corporate_account'::character varying])::text[]))),
    CONSTRAINT bookings_payment_status_check CHECK (((payment_status)::text = ANY ((ARRAY['pending'::character varying, 'paid'::character varying, 'partial'::character varying, 'failed'::character varying, 'refunded'::character varying])::text[]))),
    CONSTRAINT bookings_recurrence_pattern_check CHECK (((recurrence_pattern)::text = ANY ((ARRAY['daily'::character varying, 'weekly'::character varying, 'monthly'::character varying])::text[]))),
    CONSTRAINT bookings_status_check CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'pending'::character varying, 'pending_approval'::character varying, 'confirmed'::character varying, 'in_progress'::character varying, 'completed'::character varying, 'cancelled'::character varying])::text[]))),
    CONSTRAINT bookings_trip_status_check CHECK (((trip_status)::text = ANY ((ARRAY['not_started'::character varying, 'in_progress'::character varying, 'completed'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: COLUMN bookings.estimated_duration; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.bookings.estimated_duration IS 'Duration in minutes';


--
-- Name: COLUMN bookings.actual_duration; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.bookings.actual_duration IS 'Duration in minutes';


--
-- Name: COLUMN bookings.customer_rating; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.bookings.customer_rating IS 'Rating from 1-5';


--
-- Name: COLUMN bookings.driver_rating; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.bookings.driver_rating IS 'Rating from 1-5';


--
-- Name: business_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.business_settings (
    id uuid NOT NULL,
    type character varying(255),
    value text,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: cms_content_types; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cms_content_types (
    id uuid NOT NULL,
    title character varying(255),
    slug character varying(255) NOT NULL,
    description text NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL,
    icon character varying(255),
    template_config json,
    display_order integer,
    url_prefix character varying(255)
);


--
-- Name: cms_contents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cms_contents (
    id uuid NOT NULL,
    cms_content_type_id uuid NOT NULL,
    title character varying(255),
    slug character varying(255) NOT NULL,
    author character varying(255),
    thumbnail character varying(255),
    body text,
    meta_title character varying(255),
    meta_description text,
    meta_tags text,
    is_active boolean DEFAULT true NOT NULL,
    display_order integer,
    url character varying(255),
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    published_at timestamp(0) without time zone,
    status character varying(255) DEFAULT 'draft'::character varying NOT NULL,
    excerpt text,
    custom_fields json,
    featured_image character varying(255),
    gallery_images json,
    views_count integer DEFAULT 0 NOT NULL,
    is_featured boolean DEFAULT false NOT NULL,
    allow_comments boolean DEFAULT true NOT NULL,
    CONSTRAINT cms_contents_status_check CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'published'::character varying, 'archived'::character varying])::text[])))
);


--
-- Name: companies; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.companies (
    id uuid NOT NULL,
    name character varying(255) NOT NULL,
    address text,
    region_id uuid,
    country_id uuid,
    state_id uuid,
    city character varying(255),
    latitude numeric(10,8),
    longitude numeric(11,8),
    is_default boolean DEFAULT false,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: countries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.countries (
    id uuid NOT NULL,
    name character varying(255) NOT NULL,
    code character varying(5),
    code3 character varying(3),
    callcode character varying(5),
    googlelode text,
    description text,
    url text,
    tagline character varying(255),
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: currencies; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.currencies (
    id uuid NOT NULL,
    code character varying(3) NOT NULL,
    name character varying(255) NOT NULL,
    symbol character varying(255),
    exrate character varying(255),
    country_id uuid,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: customer_loyalty_points; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.customer_loyalty_points (
    id uuid NOT NULL,
    customer_id uuid NOT NULL,
    total_points integer DEFAULT 0 NOT NULL,
    available_points integer DEFAULT 0 NOT NULL,
    pending_points integer DEFAULT 0 NOT NULL,
    redeemed_points integer DEFAULT 0 NOT NULL,
    expired_points integer DEFAULT 0 NOT NULL,
    current_tier character varying(255) DEFAULT 'bronze'::character varying NOT NULL,
    tier_progress_points integer DEFAULT 0 NOT NULL,
    next_tier character varying(255),
    points_to_next_tier integer DEFAULT 0 NOT NULL,
    earning_rate_multiplier numeric(3,2) DEFAULT '1'::numeric NOT NULL,
    redemption_rate_multiplier numeric(3,2) DEFAULT '1'::numeric NOT NULL,
    total_bookings integer DEFAULT 0 NOT NULL,
    total_spent numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    last_activity_date date,
    tier_upgrade_date date,
    tier_downgrade_date date,
    is_vip boolean DEFAULT false NOT NULL,
    special_privileges json,
    notes text,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: customers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.customers (
    id uuid NOT NULL,
    user_id uuid NOT NULL,
    code character varying(255),
    type character varying(255),
    sub_type character varying(255),
    category character varying(255),
    passport_number character varying(255),
    nic character varying(255),
    license_no character varying(255),
    license_expiry date,
    license_type character varying(255),
    dob date,
    wedding_date date,
    address character varying(255),
    gender character varying(255),
    postal_code character varying(255),
    country_id uuid,
    state_id uuid,
    city character varying(255),
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: demand_forecasts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.demand_forecasts (
    id uuid NOT NULL,
    month integer NOT NULL,
    year integer NOT NULL,
    service_type_id uuid NOT NULL,
    predicted_count integer,
    predicted_revenue numeric(14,2),
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: driver_assignments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.driver_assignments (
    id uuid NOT NULL,
    driver_id uuid NOT NULL,
    booking_id uuid NOT NULL,
    parent_assignment_id uuid,
    customer_name character varying(255) NOT NULL,
    service_type character varying(255) NOT NULL,
    assigned_from timestamp(0) without time zone NOT NULL,
    assigned_to timestamp(0) without time zone NOT NULL,
    assignment_type character varying(255) DEFAULT 'primary'::character varying NOT NULL,
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    overlap_type character varying(255),
    overlap_details json,
    requires_approval boolean DEFAULT false NOT NULL,
    approved_by uuid,
    approved_at timestamp(0) without time zone,
    approval_notes text,
    override_reasons json,
    manually_confirmed boolean DEFAULT false NOT NULL,
    confirmed_by uuid,
    confirmed_at timestamp(0) without time zone,
    confirmation_method character varying(255),
    confirmation_notes text,
    assigned_by uuid NOT NULL,
    actual_start timestamp(0) without time zone,
    actual_end timestamp(0) without time zone,
    assignment_notes text,
    hourly_rate numeric(8,2),
    overtime_applicable boolean DEFAULT false NOT NULL,
    special_requirements json,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT driver_assignments_assignment_type_check CHECK (((assignment_type)::text = ANY ((ARRAY['primary'::character varying, 'concurrent'::character varying, 'override'::character varying])::text[]))),
    CONSTRAINT driver_assignments_overlap_type_check CHECK (((overlap_type)::text = ANY ((ARRAY['rest_window'::character varying, 'partial_availability'::character varying, 'override'::character varying])::text[]))),
    CONSTRAINT driver_assignments_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'completed'::character varying, 'cancelled'::character varying, 'pending_approval'::character varying])::text[])))
);


--
-- Name: COLUMN driver_assignments.parent_assignment_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.driver_assignments.parent_assignment_id IS 'For concurrent assignments';


--
-- Name: COLUMN driver_assignments.overlap_details; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.driver_assignments.overlap_details IS 'Details about the overlap period';


--
-- Name: COLUMN driver_assignments.confirmation_method; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.driver_assignments.confirmation_method IS 'phone, whatsapp, sms, etc';


--
-- Name: COLUMN driver_assignments.special_requirements; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.driver_assignments.special_requirements IS 'Special requirements for this assignment';


--
-- Name: driver_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.driver_logs (
    id uuid NOT NULL,
    driver_id uuid,
    booking_id uuid,
    log_code date,
    log_date date,
    start_time time(0) without time zone,
    end_time time(0) without time zone,
    start_km integer,
    end_km integer,
    start_image character varying(255),
    end_image character varying(255),
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT driver_logs_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'approved'::character varying, 'rejected'::character varying])::text[])))
);


--
-- Name: drivers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.drivers (
    id uuid NOT NULL,
    user_id uuid NOT NULL,
    code character varying(255),
    nic character varying(255),
    license_no character varying(255),
    license_expiry date,
    license_type uuid,
    dob date,
    postal_code character varying(255),
    address character varying(255),
    country_id uuid,
    state_id uuid,
    city character varying(255),
    remarks text,
    company_id_renewal_date date,
    contract_expiry_date date,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL,
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    availability_status character varying(255) DEFAULT 'available'::character varying NOT NULL,
    current_booking_id uuid,
    current_customer_name character varying(255),
    current_service_type character varying(255),
    current_assignment_from timestamp(0) without time zone,
    current_assignment_to timestamp(0) without time zone,
    is_long_term_assignment boolean DEFAULT false NOT NULL,
    default_vehicle_id uuid,
    override_allowed boolean DEFAULT true NOT NULL,
    requires_approval_for_override boolean DEFAULT true NOT NULL,
    concurrent_assignment_possible boolean DEFAULT false NOT NULL,
    rest_windows json,
    working_schedule json,
    leave_schedule json,
    last_availability_confirmed_at timestamp(0) without time zone,
    last_confirmed_by uuid,
    last_confirmation_method character varying(255),
    total_assignments_count integer DEFAULT 0 NOT NULL,
    last_assignment_end timestamp(0) without time zone,
    average_rating numeric(3,2),
    emergency_contact_name character varying(255),
    emergency_contact_phone character varying(255),
    CONSTRAINT drivers_availability_status_check CHECK (((availability_status)::text = ANY ((ARRAY['available'::character varying, 'booked'::character varying, 'long_term'::character varying, 'resting'::character varying, 'on_leave'::character varying, 'offline'::character varying])::text[]))),
    CONSTRAINT drivers_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'inactive'::character varying, 'suspended'::character varying])::text[])))
);


--
-- Name: COLUMN drivers.rest_windows; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.drivers.rest_windows IS 'Array of rest time windows';


--
-- Name: COLUMN drivers.working_schedule; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.drivers.working_schedule IS 'Regular working hours';


--
-- Name: COLUMN drivers.leave_schedule; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.drivers.leave_schedule IS 'Scheduled leave times';


--
-- Name: COLUMN drivers.last_confirmation_method; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.drivers.last_confirmation_method IS 'phone, whatsapp, sms, etc';


--
-- Name: driving_license_types; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.driving_license_types (
    id uuid NOT NULL,
    name character varying(255),
    description text,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: driving_licenses; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.driving_licenses (
    id uuid NOT NULL,
    user_id uuid NOT NULL,
    license_number character varying(255) NOT NULL,
    license_type uuid,
    issue_date date NOT NULL,
    expiry_date date NOT NULL,
    issuing_authority character varying(255),
    license_class character varying(255),
    restrictions text,
    endorsements text,
    country character(3),
    state character varying(255),
    document_path character varying(255),
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL,
    CONSTRAINT driving_licenses_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'expired'::character varying, 'suspended'::character varying])::text[])))
);


--
-- Name: image_galleries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.image_galleries (
    id uuid NOT NULL,
    user_id uuid,
    title character varying(255),
    caption text,
    path character varying(255) NOT NULL,
    thumbnail_path character varying(255),
    sort_order integer DEFAULT 0 NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: inquiries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.inquiries (
    id uuid NOT NULL,
    customer_id uuid,
    agent_id uuid,
    assigned_to uuid,
    name character varying(255),
    email character varying(255),
    phone character varying(255),
    inquiry_type character varying(255) NOT NULL,
    subject character varying(255),
    message text NOT NULL,
    status character varying(255) DEFAULT 'open'::character varying NOT NULL,
    source character varying(255) DEFAULT 'web'::character varying NOT NULL,
    payload json,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL,
    CONSTRAINT inquiries_status_check CHECK (((status)::text = ANY ((ARRAY['open'::character varying, 'in_progress'::character varying, 'closed'::character varying, 'archived'::character varying])::text[])))
);


--
-- Name: loyalty_point_transactions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.loyalty_point_transactions (
    id uuid NOT NULL,
    customer_id uuid NOT NULL,
    booking_id uuid,
    discount_id uuid,
    type character varying(255) NOT NULL,
    points integer NOT NULL,
    balance_before integer NOT NULL,
    balance_after integer NOT NULL,
    amount_spent numeric(12,2),
    earning_rate numeric(8,4),
    earning_reason character varying(255),
    redemption_value numeric(10,2),
    redemption_rate numeric(8,4),
    redemption_reason character varying(255),
    expires_at date,
    expired_at date,
    reference_number character varying(255) NOT NULL,
    metadata json,
    description text NOT NULL,
    internal_notes text,
    processed_by uuid,
    processed_at timestamp(0) without time zone NOT NULL,
    status character varying(255) DEFAULT 'completed'::character varying NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT loyalty_point_transactions_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'completed'::character varying, 'cancelled'::character varying, 'failed'::character varying])::text[]))),
    CONSTRAINT loyalty_point_transactions_type_check CHECK (((type)::text = ANY ((ARRAY['earned'::character varying, 'redeemed'::character varying, 'expired'::character varying, 'adjusted'::character varying, 'bonus'::character varying, 'refunded'::character varying])::text[])))
);


--
-- Name: loyalty_tiers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.loyalty_tiers (
    id uuid NOT NULL,
    name character varying(255) NOT NULL,
    display_name character varying(255) NOT NULL,
    description text,
    color_code character varying(255),
    icon character varying(255),
    min_points integer DEFAULT 0 NOT NULL,
    max_points integer,
    min_bookings integer DEFAULT 0 NOT NULL,
    min_total_spent numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    months_to_maintain integer DEFAULT 12 NOT NULL,
    points_earning_multiplier numeric(3,2) DEFAULT '1'::numeric NOT NULL,
    points_redemption_multiplier numeric(3,2) DEFAULT '1'::numeric NOT NULL,
    discount_multiplier numeric(3,2) DEFAULT '1'::numeric NOT NULL,
    bonus_points_on_upgrade integer DEFAULT 0 NOT NULL,
    privileges json,
    exclusive_discounts json,
    priority_booking boolean DEFAULT false NOT NULL,
    free_cancellation boolean DEFAULT false NOT NULL,
    priority_support boolean DEFAULT false NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    is_default boolean DEFAULT false NOT NULL,
    auto_upgrade boolean DEFAULT true NOT NULL,
    auto_downgrade boolean DEFAULT true NOT NULL,
    grace_period_months integer DEFAULT 3 NOT NULL,
    min_points_to_maintain integer,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: model_has_permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.model_has_permissions (
    permission_id bigint NOT NULL,
    model_type character varying(255) NOT NULL,
    model_id character varying(255) NOT NULL
);


--
-- Name: model_has_roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.model_has_roles (
    role_id bigint NOT NULL,
    model_type character varying(255) NOT NULL,
    model_id character varying(255) NOT NULL
);


--
-- Name: notification_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notification_logs (
    id uuid NOT NULL,
    user_id uuid,
    template_id uuid NOT NULL,
    content text NOT NULL,
    channel character varying(255) NOT NULL,
    sent_at timestamp(0) without time zone NOT NULL,
    status character varying(255) DEFAULT 'sent'::character varying NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT notification_logs_status_check CHECK (((status)::text = ANY ((ARRAY['sent'::character varying, 'failed'::character varying])::text[])))
);


--
-- Name: notification_templates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notification_templates (
    id uuid NOT NULL,
    code character varying(255) NOT NULL,
    channel character varying(255) NOT NULL,
    subject character varying(255),
    body text NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: oauth_access_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.oauth_access_tokens (
    id character(80) NOT NULL,
    user_id character varying(255),
    client_id uuid NOT NULL,
    name character varying(255),
    scopes text,
    revoked boolean NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone
);


--
-- Name: oauth_auth_codes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.oauth_auth_codes (
    id character(80) NOT NULL,
    user_id character varying(255),
    client_id uuid NOT NULL,
    scopes text,
    revoked boolean NOT NULL,
    expires_at timestamp(0) without time zone
);


--
-- Name: oauth_clients; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.oauth_clients (
    id uuid NOT NULL,
    owner_type character varying(255),
    owner_id bigint,
    name character varying(255) NOT NULL,
    secret character varying(255),
    provider character varying(255),
    redirect_uris text NOT NULL,
    grant_types text NOT NULL,
    revoked boolean NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: oauth_device_codes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.oauth_device_codes (
    id character(80) NOT NULL,
    user_id bigint,
    client_id uuid NOT NULL,
    user_code character(8) NOT NULL,
    scopes text NOT NULL,
    revoked boolean NOT NULL,
    user_approved_at timestamp(0) without time zone,
    last_polled_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: oauth_refresh_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.oauth_refresh_tokens (
    id character(80) NOT NULL,
    access_token_id character(80) NOT NULL,
    revoked boolean NOT NULL,
    expires_at timestamp(0) without time zone
);


--
-- Name: payment_transactions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.payment_transactions (
    id uuid NOT NULL,
    attempt_id uuid NOT NULL,
    amount numeric(12,2) NOT NULL,
    currency_id uuid,
    gateway character varying(255) NOT NULL,
    transaction_id character varying(255),
    status character varying(255) DEFAULT 'initiated'::character varying,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.permissions (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    guard_name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: permissions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.permissions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: permissions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.permissions_id_seq OWNED BY public.permissions.id;


--
-- Name: personal_access_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.personal_access_tokens (
    id bigint NOT NULL,
    tokenable_type character varying(255) NOT NULL,
    tokenable_id bigint NOT NULL,
    name text NOT NULL,
    token character varying(64) NOT NULL,
    abilities text,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.personal_access_tokens_id_seq OWNED BY public.personal_access_tokens.id;


--
-- Name: phone_calls; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.phone_calls (
    id uuid NOT NULL,
    phone character varying(255),
    client_name character varying(255),
    summary text,
    call_time date,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: regions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.regions (
    id uuid NOT NULL,
    name character varying(255) NOT NULL,
    descripion text,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: reputations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.reputations (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    point integer DEFAULT 0 NOT NULL,
    subject_id integer,
    subject_type character varying(255),
    payee_id integer,
    meta text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: reputations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.reputations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: reputations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.reputations_id_seq OWNED BY public.reputations.id;


--
-- Name: role_has_permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.role_has_permissions (
    permission_id bigint NOT NULL,
    role_id bigint NOT NULL
);


--
-- Name: roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.roles (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    guard_name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: roles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: roles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.roles_id_seq OWNED BY public.roles.id;


--
-- Name: search_saveds; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.search_saveds (
    id uuid NOT NULL,
    session_id uuid NOT NULL,
    query_data json NOT NULL,
    results_data json,
    saved_at timestamp(0) without time zone NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: service_types; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.service_types (
    id uuid NOT NULL,
    code character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    slug character varying(255),
    type character varying(255),
    thumbnail character varying(255),
    priority integer,
    is_internal boolean,
    is_active boolean DEFAULT true,
    terms text,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    category character varying(255),
    is_inquiry boolean DEFAULT false NOT NULL
);


--
-- Name: COLUMN service_types.category; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.service_types.category IS 'Service category: airport, corporate, transport, etc.';


--
-- Name: COLUMN service_types.is_inquiry; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.service_types.is_inquiry IS 'Whether this service type requires inquiry form instead of direct booking';


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


--
-- Name: side_menus; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.side_menus (
    id uuid NOT NULL,
    title character varying(255) NOT NULL,
    icon character varying(255),
    description text,
    route_name character varying(255),
    permission_name character varying(255),
    crud_master character varying(255),
    parent_id uuid,
    sort integer DEFAULT 0 NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: staff; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.staff (
    id uuid NOT NULL,
    user_id uuid NOT NULL,
    staff_type character varying(255) NOT NULL,
    code character varying(255),
    dob date,
    license_no character varying(255),
    license_expiry date,
    address character varying(255),
    country_id uuid,
    state_id uuid,
    city uuid,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: states; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.states (
    id uuid NOT NULL,
    country_id uuid,
    name character varying(255),
    description text,
    url text,
    lng character varying(255),
    lat character varying(255),
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: system_constants; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.system_constants (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    value character varying(255),
    description character varying(255),
    is_active boolean DEFAULT true NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: system_constants_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.system_constants_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: system_constants_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.system_constants_id_seq OWNED BY public.system_constants.id;


--
-- Name: taxi_sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.taxi_sessions (
    id uuid NOT NULL,
    key character varying(255) NOT NULL,
    created_time timestamp(0) without time zone NOT NULL,
    last_accessed timestamp(0) without time zone,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: user_badges; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_badges (
    user_id uuid NOT NULL,
    badge_id uuid NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: user_contexts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_contexts (
    id uuid NOT NULL,
    user_id uuid NOT NULL,
    context_type character varying(255) NOT NULL,
    context_id uuid NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: user_media; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_media (
    id uuid NOT NULL,
    file_name character varying(255) NOT NULL,
    original_name character varying(255) NOT NULL,
    file_path character varying(255) NOT NULL,
    full_path character varying(255) NOT NULL,
    mime_type character varying(255) NOT NULL,
    size bigint NOT NULL,
    width integer,
    height integer,
    user_id uuid,
    category character varying(255) DEFAULT 'general'::character varying NOT NULL,
    is_image boolean DEFAULT false NOT NULL,
    is_thumbnail boolean DEFAULT false NOT NULL,
    parent_id uuid,
    storage_type character varying(255) DEFAULT 'local'::character varying NOT NULL,
    metadata json,
    is_active boolean DEFAULT false NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: COLUMN user_media.file_name; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.user_media.file_name IS 'Generated unique filename';


--
-- Name: COLUMN user_media.original_name; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.user_media.original_name IS 'Original filename from upload';


--
-- Name: COLUMN user_media.file_path; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.user_media.file_path IS 'Directory path where file is stored';


--
-- Name: COLUMN user_media.full_path; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.user_media.full_path IS 'Complete path including filename';


--
-- Name: COLUMN user_media.mime_type; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.user_media.mime_type IS 'File MIME type';


--
-- Name: COLUMN user_media.size; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.user_media.size IS 'File size in bytes';


--
-- Name: COLUMN user_media.width; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.user_media.width IS 'Image width in pixels';


--
-- Name: COLUMN user_media.height; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.user_media.height IS 'Image height in pixels';


--
-- Name: COLUMN user_media.user_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.user_media.user_id IS 'User who owns this file';


--
-- Name: COLUMN user_media.category; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.user_media.category IS 'File category';


--
-- Name: COLUMN user_media.is_image; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.user_media.is_image IS 'Whether this file is an image';


--
-- Name: COLUMN user_media.is_thumbnail; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.user_media.is_thumbnail IS 'Whether this is a thumbnail version';


--
-- Name: COLUMN user_media.parent_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.user_media.parent_id IS 'Parent file ID for thumbnails';


--
-- Name: COLUMN user_media.storage_type; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.user_media.storage_type IS 'Storage type (local, s3)';


--
-- Name: COLUMN user_media.metadata; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.user_media.metadata IS 'Additional metadata';


--
-- Name: COLUMN user_media.is_active; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.user_media.is_active IS 'Whether this is an active file';


--
-- Name: user_role_permission; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_role_permission (
    id uuid NOT NULL,
    role_id uuid NOT NULL,
    permission_id uuid NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id uuid NOT NULL,
    email character varying(255) NOT NULL,
    password character varying(255),
    first_name character varying(255) NOT NULL,
    last_name character varying(255),
    phone character varying(255),
    profile_image character varying(255),
    role_id uuid,
    agent_id uuid,
    email_verified_at timestamp(0) without time zone,
    phone_verified_at timestamp(0) without time zone,
    created_user_id uuid,
    updated_user_id uuid,
    is_active boolean DEFAULT true NOT NULL,
    is_verified boolean DEFAULT false NOT NULL,
    last_login_at timestamp(0) without time zone,
    password_changed_at timestamp(0) without time zone,
    two_factor_enabled boolean DEFAULT false NOT NULL,
    two_factor_secret text,
    two_factor_recovery_codes json,
    device_token character varying(255),
    timezone character varying(50),
    language character varying(10) DEFAULT 'en'::character varying NOT NULL,
    login_attempts integer DEFAULT 0 NOT NULL,
    locked_until timestamp(0) without time zone,
    social_id character varying(255),
    social_provider character varying(255),
    social_avatar character varying(255),
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    reputation integer DEFAULT 0 NOT NULL
);


--
-- Name: vehicle_addon_dependencies; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_addon_dependencies (
    id uuid NOT NULL,
    parent_addon_id uuid NOT NULL,
    required_addon_id uuid NOT NULL,
    dependency_type character varying(255) DEFAULT 'required'::character varying NOT NULL,
    description text,
    is_automatic boolean DEFAULT false NOT NULL,
    conditions json,
    minimum_quantity integer DEFAULT 1 NOT NULL,
    maximum_quantity integer,
    discount_percentage numeric(5,2),
    discount_amount numeric(10,2),
    category character varying(255),
    subcategory character varying(255),
    display_order integer DEFAULT 0 NOT NULL,
    is_featured boolean DEFAULT false NOT NULL,
    is_premium boolean DEFAULT false NOT NULL,
    requires_approval boolean DEFAULT false NOT NULL,
    affects_vehicle_selection boolean DEFAULT false NOT NULL,
    minimum_hours integer,
    maximum_hours integer,
    time_restrictions json,
    date_restrictions json,
    pricing_type character varying(255) DEFAULT 'fixed'::character varying NOT NULL,
    base_price numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    hourly_rate numeric(8,2),
    daily_rate numeric(10,2),
    per_km_rate numeric(8,2),
    percentage_rate numeric(5,2),
    available_quantity integer,
    unlimited_quantity boolean DEFAULT true NOT NULL,
    short_description text,
    features json,
    icon character varying(255),
    image_url character varying(255),
    metadata json,
    is_active boolean DEFAULT true NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT vehicle_addon_dependencies_dependency_type_check CHECK (((dependency_type)::text = ANY ((ARRAY['required'::character varying, 'recommended'::character varying, 'mutually_exclusive'::character varying, 'upgrade_path'::character varying])::text[]))),
    CONSTRAINT vehicle_addon_dependencies_pricing_type_check CHECK (((pricing_type)::text = ANY ((ARRAY['fixed'::character varying, 'hourly'::character varying, 'daily'::character varying, 'per_km'::character varying, 'percentage'::character varying])::text[])))
);


--
-- Name: COLUMN vehicle_addon_dependencies.parent_addon_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.parent_addon_id IS 'The addon that requires the dependency';


--
-- Name: COLUMN vehicle_addon_dependencies.required_addon_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.required_addon_id IS 'The required dependency addon';


--
-- Name: COLUMN vehicle_addon_dependencies.description; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.description IS 'Human readable description of the dependency';


--
-- Name: COLUMN vehicle_addon_dependencies.is_automatic; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.is_automatic IS 'Whether this dependency is added automatically';


--
-- Name: COLUMN vehicle_addon_dependencies.conditions; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.conditions IS 'Conditions under which this dependency applies';


--
-- Name: COLUMN vehicle_addon_dependencies.minimum_quantity; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.minimum_quantity IS 'Minimum quantity of required addon';


--
-- Name: COLUMN vehicle_addon_dependencies.maximum_quantity; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.maximum_quantity IS 'Maximum quantity of required addon';


--
-- Name: COLUMN vehicle_addon_dependencies.discount_percentage; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.discount_percentage IS 'Discount when both addons are selected';


--
-- Name: COLUMN vehicle_addon_dependencies.discount_amount; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.discount_amount IS 'Fixed discount amount';


--
-- Name: COLUMN vehicle_addon_dependencies.category; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.category IS 'Category for grouping addons';


--
-- Name: COLUMN vehicle_addon_dependencies.subcategory; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.subcategory IS 'Subcategory for detailed grouping';


--
-- Name: COLUMN vehicle_addon_dependencies.display_order; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.display_order IS 'Order for display in UI';


--
-- Name: COLUMN vehicle_addon_dependencies.is_featured; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.is_featured IS 'Whether to highlight this addon';


--
-- Name: COLUMN vehicle_addon_dependencies.is_premium; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.is_premium IS 'Premium addon requiring special handling';


--
-- Name: COLUMN vehicle_addon_dependencies.requires_approval; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.requires_approval IS 'Requires admin approval';


--
-- Name: COLUMN vehicle_addon_dependencies.affects_vehicle_selection; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.affects_vehicle_selection IS 'Whether this addon affects available vehicles';


--
-- Name: COLUMN vehicle_addon_dependencies.minimum_hours; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.minimum_hours IS 'Minimum booking duration required';


--
-- Name: COLUMN vehicle_addon_dependencies.maximum_hours; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.maximum_hours IS 'Maximum booking duration allowed';


--
-- Name: COLUMN vehicle_addon_dependencies.time_restrictions; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.time_restrictions IS 'Time-based restrictions';


--
-- Name: COLUMN vehicle_addon_dependencies.date_restrictions; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.date_restrictions IS 'Date-based restrictions';


--
-- Name: COLUMN vehicle_addon_dependencies.base_price; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.base_price IS 'Base price for the addon';


--
-- Name: COLUMN vehicle_addon_dependencies.hourly_rate; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.hourly_rate IS 'Hourly rate if applicable';


--
-- Name: COLUMN vehicle_addon_dependencies.daily_rate; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.daily_rate IS 'Daily rate if applicable';


--
-- Name: COLUMN vehicle_addon_dependencies.per_km_rate; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.per_km_rate IS 'Per kilometer rate if applicable';


--
-- Name: COLUMN vehicle_addon_dependencies.percentage_rate; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.percentage_rate IS 'Percentage of base booking cost';


--
-- Name: COLUMN vehicle_addon_dependencies.available_quantity; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.available_quantity IS 'Available quantity in inventory';


--
-- Name: COLUMN vehicle_addon_dependencies.unlimited_quantity; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.unlimited_quantity IS 'Whether quantity is unlimited';


--
-- Name: COLUMN vehicle_addon_dependencies.short_description; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.short_description IS 'Brief description for cards';


--
-- Name: COLUMN vehicle_addon_dependencies.features; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.features IS 'List of features/benefits';


--
-- Name: COLUMN vehicle_addon_dependencies.icon; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.icon IS 'Icon class or URL';


--
-- Name: COLUMN vehicle_addon_dependencies.image_url; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.image_url IS 'Image URL for the addon';


--
-- Name: COLUMN vehicle_addon_dependencies.metadata; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_addon_dependencies.metadata IS 'Additional metadata';


--
-- Name: vehicle_addons; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_addons (
    id uuid NOT NULL,
    service_type_id uuid,
    name character varying(255) NOT NULL,
    thumbnail character varying(255),
    min_qty character varying(255),
    max_qty character varying(255),
    description text,
    amount numeric(12,2) NOT NULL,
    rate_type character varying(255) DEFAULT 'flat'::character varying NOT NULL,
    valid_from date,
    valid_to date,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL,
    billing_type character varying(255) DEFAULT 'per_day'::character varying NOT NULL,
    CONSTRAINT vehicle_addons_billing_type_check CHECK (((billing_type)::text = ANY ((ARRAY['per_package'::character varying, 'per_day'::character varying, 'per_hour'::character varying])::text[]))),
    CONSTRAINT vehicle_addons_rate_type_check CHECK (((rate_type)::text = ANY ((ARRAY['flat'::character varying, 'percentage'::character varying])::text[])))
);


--
-- Name: vehicle_assignments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_assignments (
    id uuid NOT NULL,
    vehicle_id uuid NOT NULL,
    booking_id uuid NOT NULL,
    parent_assignment_id uuid,
    customer_name character varying(255) NOT NULL,
    service_type character varying(255) NOT NULL,
    assigned_from timestamp(0) without time zone NOT NULL,
    assigned_to timestamp(0) without time zone NOT NULL,
    assignment_type character varying(255) DEFAULT 'primary'::character varying NOT NULL,
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    overlap_type character varying(255),
    overlap_details json,
    requires_approval boolean DEFAULT false NOT NULL,
    approved_by uuid,
    approved_at timestamp(0) without time zone,
    approval_notes text,
    override_reasons json,
    manually_confirmed boolean DEFAULT false NOT NULL,
    confirmed_by uuid,
    confirmed_at timestamp(0) without time zone,
    confirmation_method character varying(255),
    confirmation_notes text,
    assigned_by uuid NOT NULL,
    actual_start timestamp(0) without time zone,
    actual_end timestamp(0) without time zone,
    assignment_notes text,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    maintenance_window json,
    fuel_level numeric(5,2),
    mileage_start integer,
    mileage_end integer,
    special_requirements json,
    CONSTRAINT vehicle_assignments_assignment_type_check CHECK (((assignment_type)::text = ANY ((ARRAY['primary'::character varying, 'concurrent'::character varying, 'override'::character varying])::text[]))),
    CONSTRAINT vehicle_assignments_overlap_type_check CHECK (((overlap_type IS NULL) OR ((overlap_type)::text = ANY ((ARRAY['rest_window'::character varying, 'partial_availability'::character varying, 'override'::character varying, 'concurrent'::character varying])::text[])))),
    CONSTRAINT vehicle_assignments_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'completed'::character varying, 'cancelled'::character varying, 'pending_approval'::character varying])::text[])))
);


--
-- Name: COLUMN vehicle_assignments.parent_assignment_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_assignments.parent_assignment_id IS 'For concurrent assignments';


--
-- Name: COLUMN vehicle_assignments.overlap_details; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_assignments.overlap_details IS 'Details about the overlap period';


--
-- Name: COLUMN vehicle_assignments.confirmation_method; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_assignments.confirmation_method IS 'phone, whatsapp, sms, etc';


--
-- Name: COLUMN vehicle_assignments.maintenance_window; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_assignments.maintenance_window IS 'Maintenance window details';


--
-- Name: COLUMN vehicle_assignments.fuel_level; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_assignments.fuel_level IS 'Fuel level at assignment start/end';


--
-- Name: COLUMN vehicle_assignments.mileage_start; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_assignments.mileage_start IS 'Mileage at assignment start';


--
-- Name: COLUMN vehicle_assignments.mileage_end; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_assignments.mileage_end IS 'Mileage at assignment end';


--
-- Name: COLUMN vehicle_assignments.special_requirements; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_assignments.special_requirements IS 'Special assignment requirements';


--
-- Name: vehicle_categories; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_categories (
    id uuid NOT NULL,
    name character varying(255),
    description text,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: vehicle_classes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_classes (
    id uuid NOT NULL,
    name character varying(255),
    description text,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: vehicle_companies; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_companies (
    id uuid NOT NULL,
    vehicle_id uuid,
    company_id uuid,
    is_active boolean DEFAULT true NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: vehicle_contract_types; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_contract_types (
    id uuid NOT NULL,
    name character varying(255),
    description text,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: vehicle_discounts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_discounts (
    id uuid NOT NULL,
    code character varying(50) NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    service_type_id uuid,
    vehicle_group_id uuid,
    amount numeric(10,2) NOT NULL,
    is_percentage boolean DEFAULT true NOT NULL,
    applies_to character varying(255) DEFAULT 'subtotal'::character varying NOT NULL,
    valid_from timestamp(0) without time zone,
    valid_to timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL,
    minimum_amount numeric(10,2),
    maximum_discount numeric(10,2),
    usage_limit integer,
    usage_count integer DEFAULT 0 NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT vehicle_discounts_applies_to_check CHECK (((applies_to)::text = ANY ((ARRAY['subtotal'::character varying, 'total'::character varying, 'addons'::character varying])::text[])))
);


--
-- Name: vehicle_fuel_types; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_fuel_types (
    id uuid NOT NULL,
    name character varying(255),
    description text,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: vehicle_grades; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_grades (
    id uuid NOT NULL,
    name character varying(255),
    description text,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: vehicle_group_common_rate_pricing; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_group_common_rate_pricing (
    id uuid NOT NULL,
    vehicle_group_id uuid NOT NULL,
    common_rate_definition_id uuid NOT NULL,
    value numeric(10,2),
    is_active boolean DEFAULT true NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: vehicle_group_pricing; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_group_pricing (
    id uuid NOT NULL,
    slab_definition_id uuid NOT NULL,
    vehicle_group_id uuid,
    rate numeric(10,2),
    rate_type character varying(255) DEFAULT 'per_day'::character varying NOT NULL,
    minimum_charge numeric(10,2),
    includes_fuel boolean DEFAULT false NOT NULL,
    includes_driver boolean DEFAULT false NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT vehicle_group_pricing_rate_type_check CHECK (((rate_type)::text = ANY ((ARRAY['per_hour'::character varying, 'per_day'::character varying, 'flat_rate'::character varying])::text[])))
);


--
-- Name: vehicle_groups; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_groups (
    id uuid NOT NULL,
    grade_id uuid,
    make_id uuid,
    model_id uuid,
    transmission_id uuid,
    fuel_type_id uuid,
    category_id uuid,
    class_id uuid,
    name character varying(255) NOT NULL,
    description text,
    specs json,
    images json,
    is_active boolean DEFAULT true NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: vehicle_insurance_providers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_insurance_providers (
    id uuid NOT NULL,
    name character varying(255) NOT NULL,
    contact character varying(255),
    description text,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: vehicle_insurance_types; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_insurance_types (
    id uuid NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: vehicle_insurances; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_insurances (
    id uuid NOT NULL,
    vehicle_id uuid NOT NULL,
    provider_id uuid NOT NULL,
    insurance_type_id uuid NOT NULL,
    policy_number character varying(255),
    start_date date,
    end_date date,
    premium_amount numeric(12,2),
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: vehicle_maintenance_records; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_maintenance_records (
    id uuid NOT NULL,
    vehicle_id uuid NOT NULL,
    schedule_id uuid NOT NULL,
    performed_date date NOT NULL,
    cost numeric(12,2) NOT NULL,
    notes text,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    CONSTRAINT vehicle_maintenance_records_status_check CHECK (((status)::text = ANY ((ARRAY['completed'::character varying, 'pending'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: vehicle_maintenance_schedules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_maintenance_schedules (
    id uuid NOT NULL,
    vehicle_id uuid NOT NULL,
    type character varying(255) NOT NULL,
    interval_km integer,
    interval_days integer,
    next_due_date date,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: vehicle_makes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_makes (
    id uuid NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    thumbnail character varying(255),
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: vehicle_models; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_models (
    id uuid NOT NULL,
    make_id uuid NOT NULL,
    name character varying(255),
    description text,
    thumbnail character varying(255),
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: vehicle_owner_types; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_owner_types (
    id uuid NOT NULL,
    name character varying(255),
    description text,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: vehicle_owners; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_owners (
    id uuid NOT NULL,
    owner_type_id uuid NOT NULL,
    user_id uuid NOT NULL,
    address character varying(255),
    country_id uuid,
    state_id uuid,
    city character varying(255),
    postal_code character varying(255),
    dob date,
    license_expiry date,
    license_number character varying(255),
    notes text,
    is_active boolean DEFAULT true NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: vehicle_pricing_bulk_operations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_pricing_bulk_operations (
    id uuid NOT NULL,
    operation_type character varying(255) NOT NULL,
    operation_name character varying(255) NOT NULL,
    description text,
    operation_data json NOT NULL,
    affected_records json NOT NULL,
    status character varying(255) NOT NULL,
    total_records integer DEFAULT 0 NOT NULL,
    processed_records integer DEFAULT 0 NOT NULL,
    successful_records integer DEFAULT 0 NOT NULL,
    failed_records integer DEFAULT 0 NOT NULL,
    errors json,
    initiated_by uuid NOT NULL,
    started_at timestamp(0) without time zone,
    completed_at timestamp(0) without time zone,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT vehicle_pricing_bulk_operations_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'in_progress'::character varying, 'completed'::character varying, 'failed'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: vehicle_pricing_calculation_definitions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_pricing_calculation_definitions (
    id uuid NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    service_type_id uuid NOT NULL,
    status character varying(255) DEFAULT 'draft'::character varying NOT NULL,
    formula text NOT NULL,
    variables json,
    conditions json,
    created_by uuid NOT NULL,
    updated_by uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT vehicle_pricing_calculation_definitions_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'inactive'::character varying, 'draft'::character varying])::text[])))
);


--
-- Name: vehicle_pricing_calculations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_pricing_calculations (
    id uuid NOT NULL,
    booking_id uuid NOT NULL,
    slab_definition_id uuid NOT NULL,
    vehicle_group_id uuid NOT NULL,
    hours_used integer DEFAULT 0,
    base_rate numeric(10,2),
    base_amount numeric(10,2),
    addons_applied json,
    addons_total numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    discount_percentage numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    discount_amount numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    subtotal numeric(10,2),
    tax_percentage numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    tax_amount numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    total_amount numeric(10,2),
    calculation_notes text,
    is_active boolean DEFAULT true NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: vehicle_pricing_common_rate_definitions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_pricing_common_rate_definitions (
    id uuid NOT NULL,
    name character varying(100) NOT NULL,
    code character varying(100),
    service_type_id uuid,
    vehicle_group_id uuid,
    description text,
    common_rate_type character varying(255) DEFAULT 'fixed_amount'::character varying NOT NULL,
    is_mandatory boolean DEFAULT false NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: vehicle_pricing_history; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_pricing_history (
    id uuid NOT NULL,
    vehicle_group_id uuid NOT NULL,
    service_type_id uuid NOT NULL,
    pricing_slab_definition_id uuid,
    common_rate_definition_id uuid,
    record_type character varying(255) DEFAULT 'slab_pricing'::character varying NOT NULL,
    old_rate numeric(10,2) NOT NULL,
    new_rate numeric(10,2) NOT NULL,
    rate_change numeric(10,2) NOT NULL,
    percentage_change numeric(10,2) NOT NULL,
    change_type character varying(255) NOT NULL,
    change_reason text,
    old_pricing_data json,
    new_pricing_data json,
    changed_by uuid NOT NULL,
    changed_at timestamp(0) without time zone NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT vehicle_pricing_history_change_type_check CHECK (((change_type)::text = ANY ((ARRAY['increase'::character varying, 'decrease'::character varying, 'no_change'::character varying])::text[]))),
    CONSTRAINT vehicle_pricing_history_record_type_check CHECK (((record_type)::text = ANY ((ARRAY['slab_pricing'::character varying, 'common_rate_pricing'::character varying])::text[])))
);


--
-- Name: vehicle_pricing_notifications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_pricing_notifications (
    id uuid NOT NULL,
    notification_type character varying(255) NOT NULL,
    title character varying(255) NOT NULL,
    message text NOT NULL,
    data json,
    related_id uuid,
    related_type character varying(255),
    user_id uuid NOT NULL,
    is_read boolean DEFAULT false NOT NULL,
    read_at timestamp(0) without time zone,
    priority character varying(255) DEFAULT 'normal'::character varying NOT NULL,
    action_buttons json,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT vehicle_pricing_notifications_priority_check CHECK (((priority)::text = ANY ((ARRAY['low'::character varying, 'normal'::character varying, 'high'::character varying, 'urgent'::character varying])::text[])))
);


--
-- Name: vehicle_pricing_slab_definitions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_pricing_slab_definitions (
    id uuid NOT NULL,
    service_type_id uuid NOT NULL,
    name character varying(255) NOT NULL,
    type character varying(255),
    min_hours integer,
    max_hours integer,
    min_days integer,
    max_days integer,
    sort_order integer DEFAULT 1 NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    max_km_per_day integer,
    max_km_per_package integer
);


--
-- Name: COLUMN vehicle_pricing_slab_definitions.max_km_per_day; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_pricing_slab_definitions.max_km_per_day IS 'Maximum kilometers allowed per day for this slab';


--
-- Name: COLUMN vehicle_pricing_slab_definitions.max_km_per_package; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_pricing_slab_definitions.max_km_per_package IS 'Maximum kilometers allowed for the entire package/duration';


--
-- Name: vehicle_pricing_slabs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_pricing_slabs (
    id uuid NOT NULL,
    service_type_id uuid NOT NULL,
    min_days integer NOT NULL,
    max_days integer NOT NULL,
    base_rate numeric(12,2) NOT NULL,
    rate_type character varying(255) DEFAULT 'flat'::character varying NOT NULL,
    extra_rate numeric(12,2),
    region_id uuid,
    valid_from date,
    valid_to date,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL,
    CONSTRAINT vehicle_pricing_slabs_rate_type_check CHECK (((rate_type)::text = ANY ((ARRAY['flat'::character varying, 'percentage'::character varying])::text[])))
);


--
-- Name: vehicle_transmissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_transmissions (
    id uuid NOT NULL,
    name character varying(255),
    description text,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: vehicles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicles (
    id uuid NOT NULL,
    contract_type_id uuid,
    company_id uuid,
    owner_id uuid,
    vehicle_group_id uuid,
    title character varying(255),
    registration_no character varying(255),
    chasis_no character varying(255),
    engine_no character varying(255),
    license_plate character varying(255),
    model_year integer,
    color character varying(255),
    no_od_doors character varying(255),
    ac character varying(255),
    thumbnail character varying(255),
    slug character varying(255),
    bags character varying(255),
    seats character varying(255),
    refundable_deposit character varying(255),
    year character varying(255),
    tagline character varying(255),
    description text,
    is_active boolean DEFAULT true,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    availability_status character varying(255) DEFAULT 'available'::character varying NOT NULL,
    current_booking_id uuid,
    current_customer_name character varying(255),
    current_service_type character varying(255),
    current_assignment_from timestamp(0) without time zone,
    current_assignment_to timestamp(0) without time zone,
    is_long_term_assignment boolean DEFAULT false NOT NULL,
    override_allowed boolean DEFAULT true NOT NULL,
    requires_approval_for_override boolean DEFAULT true NOT NULL,
    concurrent_assignment_possible boolean DEFAULT false NOT NULL,
    is_self_driven_compatible boolean DEFAULT true NOT NULL,
    has_automatic_transmission boolean DEFAULT false NOT NULL,
    has_power_steering boolean DEFAULT true NOT NULL,
    has_gps_enabled boolean DEFAULT false NOT NULL,
    rest_windows json,
    maintenance_schedule json,
    last_availability_confirmed_at timestamp(0) without time zone,
    last_confirmed_by uuid,
    total_assignments_count integer DEFAULT 0 NOT NULL,
    last_assignment_end timestamp(0) without time zone,
    default_driver_id uuid,
    force_default_driver boolean DEFAULT false NOT NULL,
    allow_concurrent_assignments boolean DEFAULT true NOT NULL,
    CONSTRAINT vehicles_availability_status_check CHECK (((availability_status)::text = ANY ((ARRAY['available'::character varying, 'booked'::character varying, 'long_term'::character varying, 'resting'::character varying, 'maintenance'::character varying, 'offline'::character varying])::text[]))),
    CONSTRAINT vehicles_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'inactive'::character varying, 'maintenance'::character varying])::text[])))
);


--
-- Name: COLUMN vehicles.rest_windows; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicles.rest_windows IS 'Array of rest time windows';


--
-- Name: COLUMN vehicles.maintenance_schedule; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicles.maintenance_schedule IS 'Scheduled maintenance times';


--
-- Name: vip_types; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vip_types (
    id uuid NOT NULL,
    name character varying(255),
    description text,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: website_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.website_settings (
    id uuid NOT NULL,
    type character varying(255),
    value text,
    created_user_id uuid,
    updated_user_id uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL
);


--
-- Name: activity_log id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.activity_log ALTER COLUMN id SET DEFAULT nextval('public.activity_log_id_seq'::regclass);


--
-- Name: badges id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.badges ALTER COLUMN id SET DEFAULT nextval('public.badges_id_seq'::regclass);


--
-- Name: booking_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.booking_items ALTER COLUMN id SET DEFAULT nextval('public.booking_items_id_seq'::regclass);


--
-- Name: booking_status_histories id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.booking_status_histories ALTER COLUMN id SET DEFAULT nextval('public.booking_status_histories_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: permissions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions ALTER COLUMN id SET DEFAULT nextval('public.permissions_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('public.personal_access_tokens_id_seq'::regclass);


--
-- Name: reputations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reputations ALTER COLUMN id SET DEFAULT nextval('public.reputations_id_seq'::regclass);


--
-- Name: roles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles ALTER COLUMN id SET DEFAULT nextval('public.roles_id_seq'::regclass);


--
-- Name: system_constants id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.system_constants ALTER COLUMN id SET DEFAULT nextval('public.system_constants_id_seq'::regclass);


--
-- Name: activity_log activity_log_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.activity_log
    ADD CONSTRAINT activity_log_pkey PRIMARY KEY (id);


--
-- Name: agent_api_sessions agent_api_sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agent_api_sessions
    ADD CONSTRAINT agent_api_sessions_pkey PRIMARY KEY (id);


--
-- Name: agent_apis agent_apis_api_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agent_apis
    ADD CONSTRAINT agent_apis_api_key_unique UNIQUE (api_key);


--
-- Name: agent_apis agent_apis_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agent_apis
    ADD CONSTRAINT agent_apis_pkey PRIMARY KEY (id);


--
-- Name: agent_commissions agent_commissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agent_commissions
    ADD CONSTRAINT agent_commissions_pkey PRIMARY KEY (id);


--
-- Name: agents agents_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agents
    ADD CONSTRAINT agents_code_unique UNIQUE (code);


--
-- Name: agents agents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agents
    ADD CONSTRAINT agents_pkey PRIMARY KEY (id);


--
-- Name: audit_logs audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_pkey PRIMARY KEY (id);


--
-- Name: badges badges_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.badges
    ADD CONSTRAINT badges_pkey PRIMARY KEY (id);


--
-- Name: billing_addresses billing_addresses_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_addresses
    ADD CONSTRAINT billing_addresses_pkey PRIMARY KEY (id);


--
-- Name: booking_addons booking_addons_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.booking_addons
    ADD CONSTRAINT booking_addons_pkey PRIMARY KEY (id);


--
-- Name: booking_approvals booking_approvals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.booking_approvals
    ADD CONSTRAINT booking_approvals_pkey PRIMARY KEY (id);


--
-- Name: booking_channels booking_channels_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.booking_channels
    ADD CONSTRAINT booking_channels_pkey PRIMARY KEY (id);


--
-- Name: booking_discounts booking_discounts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.booking_discounts
    ADD CONSTRAINT booking_discounts_pkey PRIMARY KEY (id);


--
-- Name: booking_dispatches booking_dispatches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.booking_dispatches
    ADD CONSTRAINT booking_dispatches_pkey PRIMARY KEY (id);


--
-- Name: booking_items booking_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.booking_items
    ADD CONSTRAINT booking_items_pkey PRIMARY KEY (id);


--
-- Name: booking_pricings booking_pricings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.booking_pricings
    ADD CONSTRAINT booking_pricings_pkey PRIMARY KEY (id);


--
-- Name: booking_qc_repair_items booking_qc_repair_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.booking_qc_repair_items
    ADD CONSTRAINT booking_qc_repair_items_pkey PRIMARY KEY (id);


--
-- Name: booking_qcs booking_qcs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.booking_qcs
    ADD CONSTRAINT booking_qcs_pkey PRIMARY KEY (id);


--
-- Name: booking_searches booking_searches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.booking_searches
    ADD CONSTRAINT booking_searches_pkey PRIMARY KEY (id);


--
-- Name: booking_status_histories booking_status_histories_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.booking_status_histories
    ADD CONSTRAINT booking_status_histories_pkey PRIMARY KEY (id);


--
-- Name: booking_statuses booking_statuses_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.booking_statuses
    ADD CONSTRAINT booking_statuses_pkey PRIMARY KEY (id);


--
-- Name: booking_variable_customizations booking_variable_customizations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.booking_variable_customizations
    ADD CONSTRAINT booking_variable_customizations_pkey PRIMARY KEY (id);


--
-- Name: bookings bookings_booking_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bookings
    ADD CONSTRAINT bookings_booking_number_unique UNIQUE (booking_number);


--
-- Name: bookings bookings_confirmation_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bookings
    ADD CONSTRAINT bookings_confirmation_number_unique UNIQUE (confirmation_number);


--
-- Name: bookings bookings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bookings
    ADD CONSTRAINT bookings_pkey PRIMARY KEY (id);


--
-- Name: business_settings business_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.business_settings
    ADD CONSTRAINT business_settings_pkey PRIMARY KEY (id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: cms_content_types cms_content_types_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cms_content_types
    ADD CONSTRAINT cms_content_types_pkey PRIMARY KEY (id);


--
-- Name: cms_content_types cms_content_types_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cms_content_types
    ADD CONSTRAINT cms_content_types_slug_unique UNIQUE (slug);


--
-- Name: cms_contents cms_contents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cms_contents
    ADD CONSTRAINT cms_contents_pkey PRIMARY KEY (id);


--
-- Name: cms_contents cms_contents_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cms_contents
    ADD CONSTRAINT cms_contents_slug_unique UNIQUE (slug);


--
-- Name: companies companies_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.companies
    ADD CONSTRAINT companies_pkey PRIMARY KEY (id);


--
-- Name: countries countries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.countries
    ADD CONSTRAINT countries_pkey PRIMARY KEY (id);


--
-- Name: currencies currencies_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.currencies
    ADD CONSTRAINT currencies_code_unique UNIQUE (code);


--
-- Name: currencies currencies_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.currencies
    ADD CONSTRAINT currencies_pkey PRIMARY KEY (id);


--
-- Name: customer_loyalty_points customer_loyalty_points_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_loyalty_points
    ADD CONSTRAINT customer_loyalty_points_pkey PRIMARY KEY (id);


--
-- Name: customers customers_license_no_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT customers_license_no_unique UNIQUE (license_no);


--
-- Name: customers customers_nic_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT customers_nic_unique UNIQUE (nic);


--
-- Name: customers customers_passport_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT customers_passport_number_unique UNIQUE (passport_number);


--
-- Name: customers customers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT customers_pkey PRIMARY KEY (id);


--
-- Name: demand_forecasts demand_forecasts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.demand_forecasts
    ADD CONSTRAINT demand_forecasts_pkey PRIMARY KEY (id);


--
-- Name: driver_assignments driver_assignments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.driver_assignments
    ADD CONSTRAINT driver_assignments_pkey PRIMARY KEY (id);


--
-- Name: driver_logs driver_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.driver_logs
    ADD CONSTRAINT driver_logs_pkey PRIMARY KEY (id);


--
-- Name: drivers drivers_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.drivers
    ADD CONSTRAINT drivers_code_unique UNIQUE (code);


--
-- Name: drivers drivers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.drivers
    ADD CONSTRAINT drivers_pkey PRIMARY KEY (id);


--
-- Name: driving_license_types driving_license_types_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.driving_license_types
    ADD CONSTRAINT driving_license_types_pkey PRIMARY KEY (id);


--
-- Name: driving_licenses driving_licenses_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.driving_licenses
    ADD CONSTRAINT driving_licenses_pkey PRIMARY KEY (id);


--
-- Name: image_galleries image_galleries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.image_galleries
    ADD CONSTRAINT image_galleries_pkey PRIMARY KEY (id);


--
-- Name: inquiries inquiries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inquiries
    ADD CONSTRAINT inquiries_pkey PRIMARY KEY (id);


--
-- Name: loyalty_point_transactions loyalty_point_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loyalty_point_transactions
    ADD CONSTRAINT loyalty_point_transactions_pkey PRIMARY KEY (id);


--
-- Name: loyalty_point_transactions loyalty_point_transactions_reference_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loyalty_point_transactions
    ADD CONSTRAINT loyalty_point_transactions_reference_number_unique UNIQUE (reference_number);


--
-- Name: loyalty_tiers loyalty_tiers_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loyalty_tiers
    ADD CONSTRAINT loyalty_tiers_name_unique UNIQUE (name);


--
-- Name: loyalty_tiers loyalty_tiers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.loyalty_tiers
    ADD CONSTRAINT loyalty_tiers_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: model_has_permissions model_has_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.model_has_permissions
    ADD CONSTRAINT model_has_permissions_pkey PRIMARY KEY (permission_id, model_id, model_type);


--
-- Name: model_has_roles model_has_roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.model_has_roles
    ADD CONSTRAINT model_has_roles_pkey PRIMARY KEY (role_id, model_id, model_type);


--
-- Name: notification_logs notification_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_logs
    ADD CONSTRAINT notification_logs_pkey PRIMARY KEY (id);


--
-- Name: notification_templates notification_templates_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_templates
    ADD CONSTRAINT notification_templates_code_unique UNIQUE (code);


--
-- Name: notification_templates notification_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_templates
    ADD CONSTRAINT notification_templates_pkey PRIMARY KEY (id);


--
-- Name: oauth_access_tokens oauth_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.oauth_access_tokens
    ADD CONSTRAINT oauth_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: oauth_auth_codes oauth_auth_codes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.oauth_auth_codes
    ADD CONSTRAINT oauth_auth_codes_pkey PRIMARY KEY (id);


--
-- Name: oauth_clients oauth_clients_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.oauth_clients
    ADD CONSTRAINT oauth_clients_pkey PRIMARY KEY (id);


--
-- Name: oauth_device_codes oauth_device_codes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.oauth_device_codes
    ADD CONSTRAINT oauth_device_codes_pkey PRIMARY KEY (id);


--
-- Name: oauth_device_codes oauth_device_codes_user_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.oauth_device_codes
    ADD CONSTRAINT oauth_device_codes_user_code_unique UNIQUE (user_code);


--
-- Name: oauth_refresh_tokens oauth_refresh_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.oauth_refresh_tokens
    ADD CONSTRAINT oauth_refresh_tokens_pkey PRIMARY KEY (id);


--
-- Name: payment_transactions payment_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_transactions
    ADD CONSTRAINT payment_transactions_pkey PRIMARY KEY (id);


--
-- Name: permissions permissions_name_guard_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_name_guard_name_unique UNIQUE (name, guard_name);


--
-- Name: permissions permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_unique UNIQUE (token);


--
-- Name: phone_calls phone_calls_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.phone_calls
    ADD CONSTRAINT phone_calls_pkey PRIMARY KEY (id);


--
-- Name: regions regions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regions
    ADD CONSTRAINT regions_pkey PRIMARY KEY (id);


--
-- Name: reputations reputations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reputations
    ADD CONSTRAINT reputations_pkey PRIMARY KEY (id);


--
-- Name: role_has_permissions role_has_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_has_permissions
    ADD CONSTRAINT role_has_permissions_pkey PRIMARY KEY (permission_id, role_id);


--
-- Name: roles roles_name_guard_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_name_guard_name_unique UNIQUE (name, guard_name);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: search_saveds search_saveds_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.search_saveds
    ADD CONSTRAINT search_saveds_pkey PRIMARY KEY (id);


--
-- Name: service_types service_types_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.service_types
    ADD CONSTRAINT service_types_code_unique UNIQUE (code);


--
-- Name: service_types service_types_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.service_types
    ADD CONSTRAINT service_types_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: side_menus side_menus_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.side_menus
    ADD CONSTRAINT side_menus_pkey PRIMARY KEY (id);


--
-- Name: staff staff_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.staff
    ADD CONSTRAINT staff_code_unique UNIQUE (code);


--
-- Name: staff staff_license_no_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.staff
    ADD CONSTRAINT staff_license_no_unique UNIQUE (license_no);


--
-- Name: staff staff_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.staff
    ADD CONSTRAINT staff_pkey PRIMARY KEY (id);


--
-- Name: states states_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.states
    ADD CONSTRAINT states_pkey PRIMARY KEY (id);


--
-- Name: system_constants system_constants_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.system_constants
    ADD CONSTRAINT system_constants_pkey PRIMARY KEY (id);


--
-- Name: taxi_sessions taxi_sessions_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.taxi_sessions
    ADD CONSTRAINT taxi_sessions_key_unique UNIQUE (key);


--
-- Name: taxi_sessions taxi_sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.taxi_sessions
    ADD CONSTRAINT taxi_sessions_pkey PRIMARY KEY (id);


--
-- Name: user_contexts unique_active_user_context; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_contexts
    ADD CONSTRAINT unique_active_user_context UNIQUE (user_id, context_type, is_active);


--
-- Name: vehicle_pricing_common_rate_definitions unique_common_rate_definition_code; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_pricing_common_rate_definitions
    ADD CONSTRAINT unique_common_rate_definition_code UNIQUE (code, service_type_id);


--
-- Name: vehicle_pricing_common_rate_definitions unique_common_rate_definition_name; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_pricing_common_rate_definitions
    ADD CONSTRAINT unique_common_rate_definition_name UNIQUE (name, service_type_id);


--
-- Name: vehicle_group_pricing unique_group_slab_pricing; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_group_pricing
    ADD CONSTRAINT unique_group_slab_pricing UNIQUE (slab_definition_id, vehicle_group_id);


--
-- Name: user_badges user_badges_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_badges
    ADD CONSTRAINT user_badges_pkey PRIMARY KEY (user_id, badge_id);


--
-- Name: user_contexts user_contexts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_contexts
    ADD CONSTRAINT user_contexts_pkey PRIMARY KEY (id);


--
-- Name: user_media user_media_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_media
    ADD CONSTRAINT user_media_pkey PRIMARY KEY (id);


--
-- Name: user_role_permission user_role_permission_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_role_permission
    ADD CONSTRAINT user_role_permission_pkey PRIMARY KEY (id);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: vehicle_addon_dependencies vehicle_addon_dependencies_parent_addon_id_required_addon_id_un; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_addon_dependencies
    ADD CONSTRAINT vehicle_addon_dependencies_parent_addon_id_required_addon_id_un UNIQUE (parent_addon_id, required_addon_id);


--
-- Name: vehicle_addon_dependencies vehicle_addon_dependencies_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_addon_dependencies
    ADD CONSTRAINT vehicle_addon_dependencies_pkey PRIMARY KEY (id);


--
-- Name: vehicle_addons vehicle_addons_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_addons
    ADD CONSTRAINT vehicle_addons_pkey PRIMARY KEY (id);


--
-- Name: vehicle_assignments vehicle_assignments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_assignments
    ADD CONSTRAINT vehicle_assignments_pkey PRIMARY KEY (id);


--
-- Name: vehicle_categories vehicle_categories_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_categories
    ADD CONSTRAINT vehicle_categories_pkey PRIMARY KEY (id);


--
-- Name: vehicle_classes vehicle_classes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_classes
    ADD CONSTRAINT vehicle_classes_pkey PRIMARY KEY (id);


--
-- Name: vehicle_companies vehicle_companies_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_companies
    ADD CONSTRAINT vehicle_companies_pkey PRIMARY KEY (id);


--
-- Name: vehicle_contract_types vehicle_contract_types_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_contract_types
    ADD CONSTRAINT vehicle_contract_types_pkey PRIMARY KEY (id);


--
-- Name: vehicle_discounts vehicle_discounts_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_discounts
    ADD CONSTRAINT vehicle_discounts_code_unique UNIQUE (code);


--
-- Name: vehicle_discounts vehicle_discounts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_discounts
    ADD CONSTRAINT vehicle_discounts_pkey PRIMARY KEY (id);


--
-- Name: vehicle_fuel_types vehicle_fuel_types_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_fuel_types
    ADD CONSTRAINT vehicle_fuel_types_pkey PRIMARY KEY (id);


--
-- Name: vehicle_grades vehicle_grades_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_grades
    ADD CONSTRAINT vehicle_grades_pkey PRIMARY KEY (id);


--
-- Name: vehicle_group_common_rate_pricing vehicle_group_common_rate_pricing_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_group_common_rate_pricing
    ADD CONSTRAINT vehicle_group_common_rate_pricing_pkey PRIMARY KEY (id);


--
-- Name: vehicle_group_pricing vehicle_group_pricing_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_group_pricing
    ADD CONSTRAINT vehicle_group_pricing_pkey PRIMARY KEY (id);


--
-- Name: vehicle_groups vehicle_groups_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_groups
    ADD CONSTRAINT vehicle_groups_pkey PRIMARY KEY (id);


--
-- Name: vehicle_insurance_providers vehicle_insurance_providers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_insurance_providers
    ADD CONSTRAINT vehicle_insurance_providers_pkey PRIMARY KEY (id);


--
-- Name: vehicle_insurance_types vehicle_insurance_types_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_insurance_types
    ADD CONSTRAINT vehicle_insurance_types_pkey PRIMARY KEY (id);


--
-- Name: vehicle_insurances vehicle_insurances_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_insurances
    ADD CONSTRAINT vehicle_insurances_pkey PRIMARY KEY (id);


--
-- Name: vehicle_maintenance_records vehicle_maintenance_records_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_maintenance_records
    ADD CONSTRAINT vehicle_maintenance_records_pkey PRIMARY KEY (id);


--
-- Name: vehicle_maintenance_schedules vehicle_maintenance_schedules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_maintenance_schedules
    ADD CONSTRAINT vehicle_maintenance_schedules_pkey PRIMARY KEY (id);


--
-- Name: vehicle_makes vehicle_makes_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_makes
    ADD CONSTRAINT vehicle_makes_name_unique UNIQUE (name);


--
-- Name: vehicle_makes vehicle_makes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_makes
    ADD CONSTRAINT vehicle_makes_pkey PRIMARY KEY (id);


--
-- Name: vehicle_models vehicle_models_make_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_models
    ADD CONSTRAINT vehicle_models_make_name_unique UNIQUE (make_id, name);


--
-- Name: vehicle_models vehicle_models_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_models
    ADD CONSTRAINT vehicle_models_pkey PRIMARY KEY (id);


--
-- Name: vehicle_owner_types vehicle_owner_types_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_owner_types
    ADD CONSTRAINT vehicle_owner_types_pkey PRIMARY KEY (id);


--
-- Name: vehicle_owners vehicle_owners_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_owners
    ADD CONSTRAINT vehicle_owners_pkey PRIMARY KEY (id);


--
-- Name: vehicle_pricing_bulk_operations vehicle_pricing_bulk_operations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_pricing_bulk_operations
    ADD CONSTRAINT vehicle_pricing_bulk_operations_pkey PRIMARY KEY (id);


--
-- Name: vehicle_pricing_calculation_definitions vehicle_pricing_calculation_definitions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_pricing_calculation_definitions
    ADD CONSTRAINT vehicle_pricing_calculation_definitions_pkey PRIMARY KEY (id);


--
-- Name: vehicle_pricing_calculations vehicle_pricing_calculations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_pricing_calculations
    ADD CONSTRAINT vehicle_pricing_calculations_pkey PRIMARY KEY (id);


--
-- Name: vehicle_pricing_common_rate_definitions vehicle_pricing_common_rate_definitions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_pricing_common_rate_definitions
    ADD CONSTRAINT vehicle_pricing_common_rate_definitions_pkey PRIMARY KEY (id);


--
-- Name: vehicle_pricing_history vehicle_pricing_history_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_pricing_history
    ADD CONSTRAINT vehicle_pricing_history_pkey PRIMARY KEY (id);


--
-- Name: vehicle_pricing_notifications vehicle_pricing_notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_pricing_notifications
    ADD CONSTRAINT vehicle_pricing_notifications_pkey PRIMARY KEY (id);


--
-- Name: vehicle_pricing_slab_definitions vehicle_pricing_slab_definitions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_pricing_slab_definitions
    ADD CONSTRAINT vehicle_pricing_slab_definitions_pkey PRIMARY KEY (id);


--
-- Name: vehicle_pricing_slab_definitions vehicle_pricing_slab_definitions_service_type_id_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_pricing_slab_definitions
    ADD CONSTRAINT vehicle_pricing_slab_definitions_service_type_id_name_unique UNIQUE (service_type_id, name);


--
-- Name: vehicle_pricing_slabs vehicle_pricing_slabs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_pricing_slabs
    ADD CONSTRAINT vehicle_pricing_slabs_pkey PRIMARY KEY (id);


--
-- Name: vehicle_transmissions vehicle_transmissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_transmissions
    ADD CONSTRAINT vehicle_transmissions_pkey PRIMARY KEY (id);


--
-- Name: vehicles vehicles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicles
    ADD CONSTRAINT vehicles_pkey PRIMARY KEY (id);


--
-- Name: vip_types vip_types_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vip_types
    ADD CONSTRAINT vip_types_pkey PRIMARY KEY (id);


--
-- Name: website_settings website_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.website_settings
    ADD CONSTRAINT website_settings_pkey PRIMARY KEY (id);


--
-- Name: activity_log_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX activity_log_is_active_index ON public.activity_log USING btree (is_active);


--
-- Name: activity_log_log_name_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX activity_log_log_name_index ON public.activity_log USING btree (log_name);


--
-- Name: agent_api_sessions_agent_api_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agent_api_sessions_agent_api_id_index ON public.agent_api_sessions USING btree (agent_api_id);


--
-- Name: agent_api_sessions_agent_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agent_api_sessions_agent_id_index ON public.agent_api_sessions USING btree (agent_id);


--
-- Name: agent_api_sessions_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agent_api_sessions_created_user_id_index ON public.agent_api_sessions USING btree (created_user_id);


--
-- Name: agent_api_sessions_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agent_api_sessions_is_active_index ON public.agent_api_sessions USING btree (is_active);


--
-- Name: agent_api_sessions_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agent_api_sessions_updated_user_id_index ON public.agent_api_sessions USING btree (updated_user_id);


--
-- Name: agent_apis_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agent_apis_created_user_id_index ON public.agent_apis USING btree (created_user_id);


--
-- Name: agent_apis_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agent_apis_is_active_index ON public.agent_apis USING btree (is_active);


--
-- Name: agent_apis_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agent_apis_updated_user_id_index ON public.agent_apis USING btree (updated_user_id);


--
-- Name: agent_commissions_agent_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agent_commissions_agent_id_index ON public.agent_commissions USING btree (agent_id);


--
-- Name: agent_commissions_booking_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agent_commissions_booking_id_index ON public.agent_commissions USING btree (booking_id);


--
-- Name: agent_commissions_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agent_commissions_created_user_id_index ON public.agent_commissions USING btree (created_user_id);


--
-- Name: agent_commissions_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agent_commissions_is_active_index ON public.agent_commissions USING btree (is_active);


--
-- Name: agent_commissions_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agent_commissions_updated_user_id_index ON public.agent_commissions USING btree (updated_user_id);


--
-- Name: agents_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agents_created_user_id_index ON public.agents USING btree (created_user_id);


--
-- Name: agents_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agents_is_active_index ON public.agents USING btree (is_active);


--
-- Name: agents_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agents_updated_user_id_index ON public.agents USING btree (updated_user_id);


--
-- Name: agents_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agents_user_id_index ON public.agents USING btree (user_id);


--
-- Name: audit_logs_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX audit_logs_created_user_id_index ON public.audit_logs USING btree (created_user_id);


--
-- Name: audit_logs_entity_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX audit_logs_entity_id_index ON public.audit_logs USING btree (entity_id);


--
-- Name: audit_logs_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX audit_logs_updated_user_id_index ON public.audit_logs USING btree (updated_user_id);


--
-- Name: audit_logs_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX audit_logs_user_id_index ON public.audit_logs USING btree (user_id);


--
-- Name: badges_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX badges_is_active_index ON public.badges USING btree (is_active);


--
-- Name: billing_addresses_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX billing_addresses_created_user_id_index ON public.billing_addresses USING btree (created_user_id);


--
-- Name: billing_addresses_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX billing_addresses_is_active_index ON public.billing_addresses USING btree (is_active);


--
-- Name: billing_addresses_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX billing_addresses_updated_user_id_index ON public.billing_addresses USING btree (updated_user_id);


--
-- Name: billing_addresses_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX billing_addresses_user_id_index ON public.billing_addresses USING btree (user_id);


--
-- Name: booking_addons_addon_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_addons_addon_id_index ON public.booking_addons USING btree (addon_id);


--
-- Name: booking_addons_booking_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_addons_booking_id_index ON public.booking_addons USING btree (booking_id);


--
-- Name: booking_addons_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_addons_created_user_id_index ON public.booking_addons USING btree (created_user_id);


--
-- Name: booking_addons_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_addons_is_active_index ON public.booking_addons USING btree (is_active);


--
-- Name: booking_addons_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_addons_updated_user_id_index ON public.booking_addons USING btree (updated_user_id);


--
-- Name: booking_approvals_approver_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_approvals_approver_id_status_index ON public.booking_approvals USING btree (approver_id, status);


--
-- Name: booking_approvals_booking_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_approvals_booking_id_status_index ON public.booking_approvals USING btree (booking_id, status);


--
-- Name: booking_approvals_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_approvals_created_user_id_index ON public.booking_approvals USING btree (created_user_id);


--
-- Name: booking_approvals_requested_by_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_approvals_requested_by_index ON public.booking_approvals USING btree (requested_by);


--
-- Name: booking_approvals_status_priority_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_approvals_status_priority_index ON public.booking_approvals USING btree (status, priority);


--
-- Name: booking_approvals_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_approvals_updated_user_id_index ON public.booking_approvals USING btree (updated_user_id);


--
-- Name: booking_channels_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_channels_created_user_id_index ON public.booking_channels USING btree (created_user_id);


--
-- Name: booking_channels_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_channels_is_active_index ON public.booking_channels USING btree (is_active);


--
-- Name: booking_channels_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_channels_updated_user_id_index ON public.booking_channels USING btree (updated_user_id);


--
-- Name: booking_discounts_approval_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_discounts_approval_status_index ON public.booking_discounts USING btree (approval_status);


--
-- Name: booking_discounts_booking_id_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_discounts_booking_id_is_active_index ON public.booking_discounts USING btree (booking_id, is_active);


--
-- Name: booking_discounts_type_application_method_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_discounts_type_application_method_index ON public.booking_discounts USING btree (type, application_method);


--
-- Name: booking_dispatches_booking_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_dispatches_booking_id_index ON public.booking_dispatches USING btree (booking_id);


--
-- Name: booking_dispatches_dispatch_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_dispatches_dispatch_status_index ON public.booking_dispatches USING btree (dispatch_status);


--
-- Name: booking_dispatches_dispatched_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_dispatches_dispatched_at_index ON public.booking_dispatches USING btree (dispatched_at);


--
-- Name: booking_dispatches_expected_return_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_dispatches_expected_return_at_index ON public.booking_dispatches USING btree (expected_return_at);


--
-- Name: booking_dispatches_vehicle_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_dispatches_vehicle_id_index ON public.booking_dispatches USING btree (vehicle_id);


--
-- Name: booking_items_booking_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_items_booking_id_status_index ON public.booking_items USING btree (booking_id, status);


--
-- Name: booking_items_booking_id_vehicle_group_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_items_booking_id_vehicle_group_id_index ON public.booking_items USING btree (booking_id, vehicle_group_id);


--
-- Name: booking_items_driver_id_from_date_to_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_items_driver_id_from_date_to_date_index ON public.booking_items USING btree (driver_id, from_date, to_date);


--
-- Name: booking_items_vehicle_group_id_from_date_to_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_items_vehicle_group_id_from_date_to_date_index ON public.booking_items USING btree (vehicle_group_id, from_date, to_date);


--
-- Name: booking_pricings_booking_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_pricings_booking_id_index ON public.booking_pricings USING btree (booking_id);


--
-- Name: booking_pricings_rate_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_pricings_rate_type_index ON public.booking_pricings USING btree (rate_type);


--
-- Name: booking_pricings_slab_definition_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_pricings_slab_definition_id_index ON public.booking_pricings USING btree (slab_definition_id);


--
-- Name: booking_pricings_vehicle_group_pricing_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_pricings_vehicle_group_pricing_id_index ON public.booking_pricings USING btree (vehicle_group_pricing_id);


--
-- Name: booking_qc_repair_items_qc_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_qc_repair_items_qc_id_index ON public.booking_qc_repair_items USING btree (qc_id);


--
-- Name: booking_qc_repair_items_repair_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_qc_repair_items_repair_status_index ON public.booking_qc_repair_items USING btree (repair_status);


--
-- Name: booking_qc_repair_items_severity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_qc_repair_items_severity_index ON public.booking_qc_repair_items USING btree (severity);


--
-- Name: booking_qcs_booking_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_qcs_booking_id_index ON public.booking_qcs USING btree (booking_id);


--
-- Name: booking_qcs_inspection_started_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_qcs_inspection_started_at_index ON public.booking_qcs USING btree (inspection_started_at);


--
-- Name: booking_qcs_inspector_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_qcs_inspector_id_index ON public.booking_qcs USING btree (inspector_id);


--
-- Name: booking_qcs_qc_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_qcs_qc_status_index ON public.booking_qcs USING btree (qc_status);


--
-- Name: booking_qcs_vehicle_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_qcs_vehicle_id_index ON public.booking_qcs USING btree (vehicle_id);


--
-- Name: booking_searches_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_searches_created_at_index ON public.booking_searches USING btree (created_at);


--
-- Name: booking_searches_customer_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_searches_customer_id_index ON public.booking_searches USING btree (customer_id);


--
-- Name: booking_searches_dropoff_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_searches_dropoff_date_index ON public.booking_searches USING btree (dropoff_date);


--
-- Name: booking_searches_pickup_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_searches_pickup_date_index ON public.booking_searches USING btree (pickup_date);


--
-- Name: booking_searches_service_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_searches_service_type_index ON public.booking_searches USING btree (service_type);


--
-- Name: booking_searches_session_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_searches_session_id_index ON public.booking_searches USING btree (session_id);


--
-- Name: booking_searches_session_id_service_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_searches_session_id_service_type_index ON public.booking_searches USING btree (session_id, service_type);


--
-- Name: booking_status_histories_booking_id_changed_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_status_histories_booking_id_changed_at_index ON public.booking_status_histories USING btree (booking_id, changed_at);


--
-- Name: booking_status_histories_new_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_status_histories_new_status_index ON public.booking_status_histories USING btree (new_status);


--
-- Name: booking_statuses_booking_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_statuses_booking_id_index ON public.booking_statuses USING btree (booking_id);


--
-- Name: booking_statuses_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_statuses_created_user_id_index ON public.booking_statuses USING btree (created_user_id);


--
-- Name: booking_statuses_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_statuses_updated_user_id_index ON public.booking_statuses USING btree (updated_user_id);


--
-- Name: booking_variable_customizations_booking_id_context_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_variable_customizations_booking_id_context_index ON public.booking_variable_customizations USING btree (booking_id, context);


--
-- Name: booking_variable_customizations_session_id_context_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_variable_customizations_session_id_context_index ON public.booking_variable_customizations USING btree (session_id, context);


--
-- Name: booking_variable_customizations_variable_name_variable_type_ind; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_variable_customizations_variable_name_variable_type_ind ON public.booking_variable_customizations USING btree (variable_name, variable_type);


--
-- Name: booking_variable_latest_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX booking_variable_latest_idx ON public.booking_variable_customizations USING btree (booking_id, variable_name, updated_at);


--
-- Name: bookings_booking_number_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX bookings_booking_number_index ON public.bookings USING btree (booking_number);


--
-- Name: bookings_confirmation_number_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX bookings_confirmation_number_index ON public.bookings USING btree (confirmation_number);


--
-- Name: bookings_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX bookings_created_user_id_index ON public.bookings USING btree (created_user_id);


--
-- Name: bookings_customer_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX bookings_customer_id_index ON public.bookings USING btree (customer_id);


--
-- Name: bookings_driver_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX bookings_driver_id_index ON public.bookings USING btree (driver_id);


--
-- Name: bookings_dropoff_latitude_dropoff_longitude_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX bookings_dropoff_latitude_dropoff_longitude_index ON public.bookings USING btree (dropoff_latitude, dropoff_longitude);


--
-- Name: bookings_is_corporate_booking_corporate_account_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX bookings_is_corporate_booking_corporate_account_id_index ON public.bookings USING btree (is_corporate_booking, corporate_account_id);


--
-- Name: bookings_is_recurring_recurrence_pattern_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX bookings_is_recurring_recurrence_pattern_index ON public.bookings USING btree (is_recurring, recurrence_pattern);


--
-- Name: bookings_payment_status_payment_method_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX bookings_payment_status_payment_method_index ON public.bookings USING btree (payment_status, payment_method);


--
-- Name: bookings_pickup_latitude_pickup_longitude_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX bookings_pickup_latitude_pickup_longitude_index ON public.bookings USING btree (pickup_latitude, pickup_longitude);


--
-- Name: bookings_requires_approval_approval_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX bookings_requires_approval_approval_status_index ON public.bookings USING btree (requires_approval, approval_status);


--
-- Name: bookings_service_type_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX bookings_service_type_id_index ON public.bookings USING btree (service_type_id);


--
-- Name: bookings_trip_status_trip_started_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX bookings_trip_status_trip_started_at_index ON public.bookings USING btree (trip_status, trip_started_at);


--
-- Name: bookings_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX bookings_updated_user_id_index ON public.bookings USING btree (updated_user_id);


--
-- Name: bookings_vehicle_group_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX bookings_vehicle_group_id_index ON public.bookings USING btree (vehicle_group_id);


--
-- Name: bookings_vehicle_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX bookings_vehicle_id_index ON public.bookings USING btree (vehicle_id);


--
-- Name: bookings_vip_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX bookings_vip_id_index ON public.bookings USING btree (vip_id);


--
-- Name: business_settings_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX business_settings_created_user_id_index ON public.business_settings USING btree (created_user_id);


--
-- Name: business_settings_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX business_settings_is_active_index ON public.business_settings USING btree (is_active);


--
-- Name: business_settings_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX business_settings_updated_user_id_index ON public.business_settings USING btree (updated_user_id);


--
-- Name: causer; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX causer ON public.activity_log USING btree (causer_type, causer_id);


--
-- Name: cms_content_types_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cms_content_types_created_user_id_index ON public.cms_content_types USING btree (created_user_id);


--
-- Name: cms_content_types_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cms_content_types_is_active_index ON public.cms_content_types USING btree (is_active);


--
-- Name: cms_content_types_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cms_content_types_updated_user_id_index ON public.cms_content_types USING btree (updated_user_id);


--
-- Name: cms_contents_cms_content_type_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cms_contents_cms_content_type_id_index ON public.cms_contents USING btree (cms_content_type_id);


--
-- Name: cms_contents_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cms_contents_created_user_id_index ON public.cms_contents USING btree (created_user_id);


--
-- Name: cms_contents_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cms_contents_updated_user_id_index ON public.cms_contents USING btree (updated_user_id);


--
-- Name: companies_city_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX companies_city_index ON public.companies USING btree (city);


--
-- Name: companies_country_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX companies_country_id_index ON public.companies USING btree (country_id);


--
-- Name: companies_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX companies_created_user_id_index ON public.companies USING btree (created_user_id);


--
-- Name: companies_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX companies_is_active_index ON public.companies USING btree (is_active);


--
-- Name: companies_latitude_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX companies_latitude_index ON public.companies USING btree (latitude);


--
-- Name: companies_longitude_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX companies_longitude_index ON public.companies USING btree (longitude);


--
-- Name: companies_region_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX companies_region_id_index ON public.companies USING btree (region_id);


--
-- Name: companies_state_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX companies_state_id_index ON public.companies USING btree (state_id);


--
-- Name: companies_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX companies_updated_user_id_index ON public.companies USING btree (updated_user_id);


--
-- Name: countries_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX countries_created_user_id_index ON public.countries USING btree (created_user_id);


--
-- Name: countries_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX countries_is_active_index ON public.countries USING btree (is_active);


--
-- Name: countries_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX countries_updated_user_id_index ON public.countries USING btree (updated_user_id);


--
-- Name: currencies_country_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX currencies_country_id_index ON public.currencies USING btree (country_id);


--
-- Name: currencies_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX currencies_created_user_id_index ON public.currencies USING btree (created_user_id);


--
-- Name: currencies_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX currencies_is_active_index ON public.currencies USING btree (is_active);


--
-- Name: currencies_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX currencies_updated_user_id_index ON public.currencies USING btree (updated_user_id);


--
-- Name: customer_loyalty_points_available_points_current_tier_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX customer_loyalty_points_available_points_current_tier_index ON public.customer_loyalty_points USING btree (available_points, current_tier);


--
-- Name: customer_loyalty_points_customer_id_current_tier_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX customer_loyalty_points_customer_id_current_tier_index ON public.customer_loyalty_points USING btree (customer_id, current_tier);


--
-- Name: customer_loyalty_points_last_activity_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX customer_loyalty_points_last_activity_date_index ON public.customer_loyalty_points USING btree (last_activity_date);


--
-- Name: customers_city_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX customers_city_index ON public.customers USING btree (city);


--
-- Name: customers_country_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX customers_country_id_index ON public.customers USING btree (country_id);


--
-- Name: customers_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX customers_created_user_id_index ON public.customers USING btree (created_user_id);


--
-- Name: customers_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX customers_is_active_index ON public.customers USING btree (is_active);


--
-- Name: customers_state_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX customers_state_id_index ON public.customers USING btree (state_id);


--
-- Name: customers_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX customers_updated_user_id_index ON public.customers USING btree (updated_user_id);


--
-- Name: customers_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX customers_user_id_index ON public.customers USING btree (user_id);


--
-- Name: demand_forecasts_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX demand_forecasts_created_user_id_index ON public.demand_forecasts USING btree (created_user_id);


--
-- Name: demand_forecasts_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX demand_forecasts_is_active_index ON public.demand_forecasts USING btree (is_active);


--
-- Name: demand_forecasts_service_type_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX demand_forecasts_service_type_id_index ON public.demand_forecasts USING btree (service_type_id);


--
-- Name: demand_forecasts_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX demand_forecasts_updated_user_id_index ON public.demand_forecasts USING btree (updated_user_id);


--
-- Name: driver_assignments_booking_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX driver_assignments_booking_id_index ON public.driver_assignments USING btree (booking_id);


--
-- Name: driver_assignments_driver_id_assigned_from_assigned_to_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX driver_assignments_driver_id_assigned_from_assigned_to_index ON public.driver_assignments USING btree (driver_id, assigned_from, assigned_to);


--
-- Name: driver_assignments_requires_approval_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX driver_assignments_requires_approval_index ON public.driver_assignments USING btree (requires_approval);


--
-- Name: driver_assignments_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX driver_assignments_status_index ON public.driver_assignments USING btree (status);


--
-- Name: driver_logs_booking_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX driver_logs_booking_id_index ON public.driver_logs USING btree (booking_id);


--
-- Name: driver_logs_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX driver_logs_created_user_id_index ON public.driver_logs USING btree (created_user_id);


--
-- Name: driver_logs_driver_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX driver_logs_driver_id_index ON public.driver_logs USING btree (driver_id);


--
-- Name: driver_logs_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX driver_logs_updated_user_id_index ON public.driver_logs USING btree (updated_user_id);


--
-- Name: drivers_city_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX drivers_city_index ON public.drivers USING btree (city);


--
-- Name: drivers_country_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX drivers_country_id_index ON public.drivers USING btree (country_id);


--
-- Name: drivers_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX drivers_created_user_id_index ON public.drivers USING btree (created_user_id);


--
-- Name: drivers_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX drivers_is_active_index ON public.drivers USING btree (is_active);


--
-- Name: drivers_state_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX drivers_state_id_index ON public.drivers USING btree (state_id);


--
-- Name: drivers_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX drivers_updated_user_id_index ON public.drivers USING btree (updated_user_id);


--
-- Name: drivers_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX drivers_user_id_index ON public.drivers USING btree (user_id);


--
-- Name: driving_license_types_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX driving_license_types_created_user_id_index ON public.driving_license_types USING btree (created_user_id);


--
-- Name: driving_license_types_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX driving_license_types_is_active_index ON public.driving_license_types USING btree (is_active);


--
-- Name: driving_license_types_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX driving_license_types_updated_user_id_index ON public.driving_license_types USING btree (updated_user_id);


--
-- Name: driving_licenses_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX driving_licenses_created_user_id_index ON public.driving_licenses USING btree (created_user_id);


--
-- Name: driving_licenses_expiry_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX driving_licenses_expiry_date_index ON public.driving_licenses USING btree (expiry_date);


--
-- Name: driving_licenses_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX driving_licenses_is_active_index ON public.driving_licenses USING btree (is_active);


--
-- Name: driving_licenses_license_number_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX driving_licenses_license_number_index ON public.driving_licenses USING btree (license_number);


--
-- Name: driving_licenses_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX driving_licenses_updated_user_id_index ON public.driving_licenses USING btree (updated_user_id);


--
-- Name: driving_licenses_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX driving_licenses_user_id_index ON public.driving_licenses USING btree (user_id);


--
-- Name: idx_booking_variable_group; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_booking_variable_group ON public.booking_variable_customizations USING btree (booking_id, vehicle_group_id, variable_name);


--
-- Name: image_galleries_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX image_galleries_created_user_id_index ON public.image_galleries USING btree (created_user_id);


--
-- Name: image_galleries_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX image_galleries_updated_user_id_index ON public.image_galleries USING btree (updated_user_id);


--
-- Name: inquiries_agent_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inquiries_agent_id_index ON public.inquiries USING btree (agent_id);


--
-- Name: inquiries_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inquiries_created_user_id_index ON public.inquiries USING btree (created_user_id);


--
-- Name: inquiries_customer_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inquiries_customer_id_index ON public.inquiries USING btree (customer_id);


--
-- Name: inquiries_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inquiries_is_active_index ON public.inquiries USING btree (is_active);


--
-- Name: inquiries_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inquiries_updated_user_id_index ON public.inquiries USING btree (updated_user_id);


--
-- Name: loyalty_point_transactions_booking_id_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loyalty_point_transactions_booking_id_type_index ON public.loyalty_point_transactions USING btree (booking_id, type);


--
-- Name: loyalty_point_transactions_customer_id_type_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loyalty_point_transactions_customer_id_type_created_at_index ON public.loyalty_point_transactions USING btree (customer_id, type, created_at);


--
-- Name: loyalty_point_transactions_expires_at_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loyalty_point_transactions_expires_at_status_index ON public.loyalty_point_transactions USING btree (expires_at, status);


--
-- Name: loyalty_point_transactions_reference_number_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loyalty_point_transactions_reference_number_index ON public.loyalty_point_transactions USING btree (reference_number);


--
-- Name: loyalty_tiers_is_active_sort_order_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loyalty_tiers_is_active_sort_order_index ON public.loyalty_tiers USING btree (is_active, sort_order);


--
-- Name: loyalty_tiers_is_default_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loyalty_tiers_is_default_index ON public.loyalty_tiers USING btree (is_default);


--
-- Name: loyalty_tiers_min_points_max_points_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX loyalty_tiers_min_points_max_points_index ON public.loyalty_tiers USING btree (min_points, max_points);


--
-- Name: model_has_permissions_model_id_model_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX model_has_permissions_model_id_model_type_index ON public.model_has_permissions USING btree (model_id, model_type);


--
-- Name: model_has_roles_model_id_model_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX model_has_roles_model_id_model_type_index ON public.model_has_roles USING btree (model_id, model_type);


--
-- Name: notification_logs_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_logs_created_user_id_index ON public.notification_logs USING btree (created_user_id);


--
-- Name: notification_logs_template_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_logs_template_id_index ON public.notification_logs USING btree (template_id);


--
-- Name: notification_logs_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_logs_updated_user_id_index ON public.notification_logs USING btree (updated_user_id);


--
-- Name: notification_logs_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_logs_user_id_index ON public.notification_logs USING btree (user_id);


--
-- Name: notification_templates_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_templates_created_user_id_index ON public.notification_templates USING btree (created_user_id);


--
-- Name: notification_templates_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_templates_updated_user_id_index ON public.notification_templates USING btree (updated_user_id);


--
-- Name: oauth_access_tokens_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX oauth_access_tokens_user_id_index ON public.oauth_access_tokens USING btree (user_id);


--
-- Name: oauth_auth_codes_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX oauth_auth_codes_user_id_index ON public.oauth_auth_codes USING btree (user_id);


--
-- Name: oauth_clients_owner_type_owner_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX oauth_clients_owner_type_owner_id_index ON public.oauth_clients USING btree (owner_type, owner_id);


--
-- Name: oauth_device_codes_client_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX oauth_device_codes_client_id_index ON public.oauth_device_codes USING btree (client_id);


--
-- Name: oauth_device_codes_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX oauth_device_codes_is_active_index ON public.oauth_device_codes USING btree (is_active);


--
-- Name: oauth_device_codes_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX oauth_device_codes_user_id_index ON public.oauth_device_codes USING btree (user_id);


--
-- Name: oauth_refresh_tokens_access_token_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX oauth_refresh_tokens_access_token_id_index ON public.oauth_refresh_tokens USING btree (access_token_id);


--
-- Name: payment_transactions_attempt_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX payment_transactions_attempt_id_index ON public.payment_transactions USING btree (attempt_id);


--
-- Name: payment_transactions_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX payment_transactions_created_user_id_index ON public.payment_transactions USING btree (created_user_id);


--
-- Name: payment_transactions_currency_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX payment_transactions_currency_id_index ON public.payment_transactions USING btree (currency_id);


--
-- Name: payment_transactions_transaction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX payment_transactions_transaction_id_index ON public.payment_transactions USING btree (transaction_id);


--
-- Name: payment_transactions_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX payment_transactions_updated_user_id_index ON public.payment_transactions USING btree (updated_user_id);


--
-- Name: permissions_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX permissions_is_active_index ON public.permissions USING btree (is_active);


--
-- Name: personal_access_tokens_tokenable_type_tokenable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON public.personal_access_tokens USING btree (tokenable_type, tokenable_id);


--
-- Name: phone_calls_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX phone_calls_created_user_id_index ON public.phone_calls USING btree (created_user_id);


--
-- Name: phone_calls_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX phone_calls_is_active_index ON public.phone_calls USING btree (is_active);


--
-- Name: phone_calls_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX phone_calls_updated_user_id_index ON public.phone_calls USING btree (updated_user_id);


--
-- Name: public_booking_approvals_approval_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX public_booking_approvals_approval_type_index ON public.booking_approvals USING btree (approval_type);


--
-- Name: public_booking_approvals_processed_by_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX public_booking_approvals_processed_by_index ON public.booking_approvals USING btree (processed_by);


--
-- Name: regions_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX regions_created_user_id_index ON public.regions USING btree (created_user_id);


--
-- Name: regions_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX regions_is_active_index ON public.regions USING btree (is_active);


--
-- Name: regions_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX regions_updated_user_id_index ON public.regions USING btree (updated_user_id);


--
-- Name: reputations_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX reputations_is_active_index ON public.reputations USING btree (is_active);


--
-- Name: roles_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX roles_is_active_index ON public.roles USING btree (is_active);


--
-- Name: search_saveds_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX search_saveds_created_user_id_index ON public.search_saveds USING btree (created_user_id);


--
-- Name: search_saveds_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX search_saveds_is_active_index ON public.search_saveds USING btree (is_active);


--
-- Name: search_saveds_session_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX search_saveds_session_id_index ON public.search_saveds USING btree (session_id);


--
-- Name: search_saveds_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX search_saveds_updated_user_id_index ON public.search_saveds USING btree (updated_user_id);


--
-- Name: service_types_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX service_types_created_user_id_index ON public.service_types USING btree (created_user_id);


--
-- Name: service_types_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX service_types_updated_user_id_index ON public.service_types USING btree (updated_user_id);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: side_menus_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX side_menus_created_user_id_index ON public.side_menus USING btree (created_user_id);


--
-- Name: side_menus_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX side_menus_is_active_index ON public.side_menus USING btree (is_active);


--
-- Name: side_menus_parent_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX side_menus_parent_id_index ON public.side_menus USING btree (parent_id);


--
-- Name: side_menus_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX side_menus_updated_user_id_index ON public.side_menus USING btree (updated_user_id);


--
-- Name: staff_city_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX staff_city_index ON public.staff USING btree (city);


--
-- Name: staff_country_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX staff_country_id_index ON public.staff USING btree (country_id);


--
-- Name: staff_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX staff_created_user_id_index ON public.staff USING btree (created_user_id);


--
-- Name: staff_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX staff_is_active_index ON public.staff USING btree (is_active);


--
-- Name: staff_state_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX staff_state_id_index ON public.staff USING btree (state_id);


--
-- Name: staff_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX staff_updated_user_id_index ON public.staff USING btree (updated_user_id);


--
-- Name: staff_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX staff_user_id_index ON public.staff USING btree (user_id);


--
-- Name: states_country_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX states_country_id_index ON public.states USING btree (country_id);


--
-- Name: states_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX states_created_user_id_index ON public.states USING btree (created_user_id);


--
-- Name: states_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX states_is_active_index ON public.states USING btree (is_active);


--
-- Name: states_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX states_updated_user_id_index ON public.states USING btree (updated_user_id);


--
-- Name: subject; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX subject ON public.activity_log USING btree (subject_type, subject_id);


--
-- Name: system_constants_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX system_constants_created_user_id_index ON public.system_constants USING btree (created_user_id);


--
-- Name: system_constants_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX system_constants_updated_user_id_index ON public.system_constants USING btree (updated_user_id);


--
-- Name: taxi_sessions_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX taxi_sessions_created_user_id_index ON public.taxi_sessions USING btree (created_user_id);


--
-- Name: taxi_sessions_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX taxi_sessions_updated_user_id_index ON public.taxi_sessions USING btree (updated_user_id);


--
-- Name: user_badges_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_badges_is_active_index ON public.user_badges USING btree (is_active);


--
-- Name: user_contexts_context_type_context_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_contexts_context_type_context_id_index ON public.user_contexts USING btree (context_type, context_id);


--
-- Name: user_contexts_user_id_context_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_contexts_user_id_context_type_index ON public.user_contexts USING btree (user_id, context_type);


--
-- Name: user_media_category_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_media_category_index ON public.user_media USING btree (category);


--
-- Name: user_media_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_media_created_at_index ON public.user_media USING btree (created_at);


--
-- Name: user_media_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_media_created_user_id_index ON public.user_media USING btree (created_user_id);


--
-- Name: user_media_is_image_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_media_is_image_index ON public.user_media USING btree (is_image);


--
-- Name: user_media_is_thumbnail_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_media_is_thumbnail_index ON public.user_media USING btree (is_thumbnail);


--
-- Name: user_media_parent_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_media_parent_id_index ON public.user_media USING btree (parent_id);


--
-- Name: user_media_storage_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_media_storage_type_index ON public.user_media USING btree (storage_type);


--
-- Name: user_media_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_media_updated_user_id_index ON public.user_media USING btree (updated_user_id);


--
-- Name: user_media_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_media_user_id_index ON public.user_media USING btree (user_id);


--
-- Name: user_role_permission_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_role_permission_created_user_id_index ON public.user_role_permission USING btree (created_user_id);


--
-- Name: user_role_permission_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_role_permission_is_active_index ON public.user_role_permission USING btree (is_active);


--
-- Name: user_role_permission_permission_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_role_permission_permission_id_index ON public.user_role_permission USING btree (permission_id);


--
-- Name: user_role_permission_role_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_role_permission_role_id_index ON public.user_role_permission USING btree (role_id);


--
-- Name: user_role_permission_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_role_permission_updated_user_id_index ON public.user_role_permission USING btree (updated_user_id);


--
-- Name: users_agent_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_agent_id_index ON public.users USING btree (agent_id);


--
-- Name: users_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_created_user_id_index ON public.users USING btree (created_user_id);


--
-- Name: users_email_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_email_is_active_index ON public.users USING btree (email, is_active);


--
-- Name: users_last_login_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_last_login_at_index ON public.users USING btree (last_login_at);


--
-- Name: users_locked_until_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_locked_until_index ON public.users USING btree (locked_until);


--
-- Name: users_phone_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_phone_is_active_index ON public.users USING btree (phone, is_active);


--
-- Name: users_role_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_role_id_index ON public.users USING btree (role_id);


--
-- Name: users_social_id_social_provider_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_social_id_social_provider_index ON public.users USING btree (social_id, social_provider);


--
-- Name: users_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_updated_user_id_index ON public.users USING btree (updated_user_id);


--
-- Name: vehicle_addon_dependencies_category_subcategory_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_addon_dependencies_category_subcategory_index ON public.vehicle_addon_dependencies USING btree (category, subcategory);


--
-- Name: vehicle_addon_dependencies_dependency_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_addon_dependencies_dependency_type_index ON public.vehicle_addon_dependencies USING btree (dependency_type);


--
-- Name: vehicle_addon_dependencies_display_order_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_addon_dependencies_display_order_index ON public.vehicle_addon_dependencies USING btree (display_order);


--
-- Name: vehicle_addon_dependencies_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_addon_dependencies_is_active_index ON public.vehicle_addon_dependencies USING btree (is_active);


--
-- Name: vehicle_addon_dependencies_is_featured_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_addon_dependencies_is_featured_index ON public.vehicle_addon_dependencies USING btree (is_featured);


--
-- Name: vehicle_addon_dependencies_parent_addon_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_addon_dependencies_parent_addon_id_index ON public.vehicle_addon_dependencies USING btree (parent_addon_id);


--
-- Name: vehicle_addon_dependencies_pricing_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_addon_dependencies_pricing_type_index ON public.vehicle_addon_dependencies USING btree (pricing_type);


--
-- Name: vehicle_addon_dependencies_required_addon_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_addon_dependencies_required_addon_id_index ON public.vehicle_addon_dependencies USING btree (required_addon_id);


--
-- Name: vehicle_addon_dependencies_requires_approval_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_addon_dependencies_requires_approval_index ON public.vehicle_addon_dependencies USING btree (requires_approval);


--
-- Name: vehicle_addons_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_addons_created_user_id_index ON public.vehicle_addons USING btree (created_user_id);


--
-- Name: vehicle_addons_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_addons_is_active_index ON public.vehicle_addons USING btree (is_active);


--
-- Name: vehicle_addons_service_type_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_addons_service_type_id_index ON public.vehicle_addons USING btree (service_type_id);


--
-- Name: vehicle_addons_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_addons_updated_user_id_index ON public.vehicle_addons USING btree (updated_user_id);


--
-- Name: vehicle_assignments_booking_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_assignments_booking_id_index ON public.vehicle_assignments USING btree (booking_id);


--
-- Name: vehicle_assignments_requires_approval_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_assignments_requires_approval_index ON public.vehicle_assignments USING btree (requires_approval);


--
-- Name: vehicle_assignments_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_assignments_status_index ON public.vehicle_assignments USING btree (status);


--
-- Name: vehicle_assignments_vehicle_id_assigned_from_assigned_to_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_assignments_vehicle_id_assigned_from_assigned_to_index ON public.vehicle_assignments USING btree (vehicle_id, assigned_from, assigned_to);


--
-- Name: vehicle_categories_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_categories_created_user_id_index ON public.vehicle_categories USING btree (created_user_id);


--
-- Name: vehicle_categories_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_categories_is_active_index ON public.vehicle_categories USING btree (is_active);


--
-- Name: vehicle_categories_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_categories_updated_user_id_index ON public.vehicle_categories USING btree (updated_user_id);


--
-- Name: vehicle_classes_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_classes_created_user_id_index ON public.vehicle_classes USING btree (created_user_id);


--
-- Name: vehicle_classes_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_classes_is_active_index ON public.vehicle_classes USING btree (is_active);


--
-- Name: vehicle_classes_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_classes_updated_user_id_index ON public.vehicle_classes USING btree (updated_user_id);


--
-- Name: vehicle_contract_types_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_contract_types_created_user_id_index ON public.vehicle_contract_types USING btree (created_user_id);


--
-- Name: vehicle_contract_types_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_contract_types_is_active_index ON public.vehicle_contract_types USING btree (is_active);


--
-- Name: vehicle_contract_types_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_contract_types_updated_user_id_index ON public.vehicle_contract_types USING btree (updated_user_id);


--
-- Name: vehicle_discounts_code_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_discounts_code_is_active_index ON public.vehicle_discounts USING btree (code, is_active);


--
-- Name: vehicle_discounts_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_discounts_is_active_index ON public.vehicle_discounts USING btree (is_active);


--
-- Name: vehicle_discounts_is_active_valid_from_valid_to_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_discounts_is_active_valid_from_valid_to_index ON public.vehicle_discounts USING btree (is_active, valid_from, valid_to);


--
-- Name: vehicle_discounts_service_type_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_discounts_service_type_id_index ON public.vehicle_discounts USING btree (service_type_id);


--
-- Name: vehicle_discounts_service_type_id_vehicle_group_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_discounts_service_type_id_vehicle_group_id_index ON public.vehicle_discounts USING btree (service_type_id, vehicle_group_id);


--
-- Name: vehicle_discounts_valid_from_valid_to_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_discounts_valid_from_valid_to_index ON public.vehicle_discounts USING btree (valid_from, valid_to);


--
-- Name: vehicle_discounts_vehicle_group_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_discounts_vehicle_group_id_index ON public.vehicle_discounts USING btree (vehicle_group_id);


--
-- Name: vehicle_fuel_types_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_fuel_types_created_user_id_index ON public.vehicle_fuel_types USING btree (created_user_id);


--
-- Name: vehicle_fuel_types_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_fuel_types_is_active_index ON public.vehicle_fuel_types USING btree (is_active);


--
-- Name: vehicle_fuel_types_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_fuel_types_updated_user_id_index ON public.vehicle_fuel_types USING btree (updated_user_id);


--
-- Name: vehicle_grades_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_grades_created_user_id_index ON public.vehicle_grades USING btree (created_user_id);


--
-- Name: vehicle_grades_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_grades_is_active_index ON public.vehicle_grades USING btree (is_active);


--
-- Name: vehicle_grades_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_grades_updated_user_id_index ON public.vehicle_grades USING btree (updated_user_id);


--
-- Name: vehicle_group_common_rate_pricing_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_group_common_rate_pricing_created_user_id_index ON public.vehicle_group_common_rate_pricing USING btree (created_user_id);


--
-- Name: vehicle_group_common_rate_pricing_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_group_common_rate_pricing_updated_user_id_index ON public.vehicle_group_common_rate_pricing USING btree (updated_user_id);


--
-- Name: vehicle_group_common_rate_pricing_vehicle_group_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_group_common_rate_pricing_vehicle_group_id_index ON public.vehicle_group_common_rate_pricing USING btree (vehicle_group_id);


--
-- Name: vehicle_group_pricing_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_group_pricing_created_user_id_index ON public.vehicle_group_pricing USING btree (created_user_id);


--
-- Name: vehicle_group_pricing_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_group_pricing_is_active_index ON public.vehicle_group_pricing USING btree (is_active);


--
-- Name: vehicle_group_pricing_slab_definition_id_vehicle_group_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_group_pricing_slab_definition_id_vehicle_group_id_index ON public.vehicle_group_pricing USING btree (slab_definition_id, vehicle_group_id);


--
-- Name: vehicle_group_pricing_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_group_pricing_updated_user_id_index ON public.vehicle_group_pricing USING btree (updated_user_id);


--
-- Name: vehicle_group_pricing_vehicle_group_id_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_group_pricing_vehicle_group_id_is_active_index ON public.vehicle_group_pricing USING btree (vehicle_group_id, is_active);


--
-- Name: vehicle_groups_category_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_groups_category_id_index ON public.vehicle_groups USING btree (category_id);


--
-- Name: vehicle_groups_class_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_groups_class_id_index ON public.vehicle_groups USING btree (class_id);


--
-- Name: vehicle_groups_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_groups_created_user_id_index ON public.vehicle_groups USING btree (created_user_id);


--
-- Name: vehicle_groups_fuel_type_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_groups_fuel_type_id_index ON public.vehicle_groups USING btree (fuel_type_id);


--
-- Name: vehicle_groups_grade_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_groups_grade_id_index ON public.vehicle_groups USING btree (grade_id);


--
-- Name: vehicle_groups_make_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_groups_make_id_index ON public.vehicle_groups USING btree (make_id);


--
-- Name: vehicle_groups_model_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_groups_model_id_index ON public.vehicle_groups USING btree (model_id);


--
-- Name: vehicle_groups_transmission_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_groups_transmission_id_index ON public.vehicle_groups USING btree (transmission_id);


--
-- Name: vehicle_groups_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_groups_updated_user_id_index ON public.vehicle_groups USING btree (updated_user_id);


--
-- Name: vehicle_insurance_providers_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_insurance_providers_created_user_id_index ON public.vehicle_insurance_providers USING btree (created_user_id);


--
-- Name: vehicle_insurance_providers_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_insurance_providers_is_active_index ON public.vehicle_insurance_providers USING btree (is_active);


--
-- Name: vehicle_insurance_providers_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_insurance_providers_updated_user_id_index ON public.vehicle_insurance_providers USING btree (updated_user_id);


--
-- Name: vehicle_insurance_types_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_insurance_types_created_user_id_index ON public.vehicle_insurance_types USING btree (created_user_id);


--
-- Name: vehicle_insurance_types_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_insurance_types_is_active_index ON public.vehicle_insurance_types USING btree (is_active);


--
-- Name: vehicle_insurance_types_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_insurance_types_updated_user_id_index ON public.vehicle_insurance_types USING btree (updated_user_id);


--
-- Name: vehicle_insurances_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_insurances_created_user_id_index ON public.vehicle_insurances USING btree (created_user_id);


--
-- Name: vehicle_insurances_insurance_type_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_insurances_insurance_type_id_index ON public.vehicle_insurances USING btree (insurance_type_id);


--
-- Name: vehicle_insurances_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_insurances_is_active_index ON public.vehicle_insurances USING btree (is_active);


--
-- Name: vehicle_insurances_provider_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_insurances_provider_id_index ON public.vehicle_insurances USING btree (provider_id);


--
-- Name: vehicle_insurances_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_insurances_updated_user_id_index ON public.vehicle_insurances USING btree (updated_user_id);


--
-- Name: vehicle_insurances_vehicle_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_insurances_vehicle_id_index ON public.vehicle_insurances USING btree (vehicle_id);


--
-- Name: vehicle_maintenance_records_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_maintenance_records_created_user_id_index ON public.vehicle_maintenance_records USING btree (created_user_id);


--
-- Name: vehicle_maintenance_records_schedule_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_maintenance_records_schedule_id_index ON public.vehicle_maintenance_records USING btree (schedule_id);


--
-- Name: vehicle_maintenance_records_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_maintenance_records_updated_user_id_index ON public.vehicle_maintenance_records USING btree (updated_user_id);


--
-- Name: vehicle_maintenance_records_vehicle_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_maintenance_records_vehicle_id_index ON public.vehicle_maintenance_records USING btree (vehicle_id);


--
-- Name: vehicle_maintenance_schedules_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_maintenance_schedules_created_user_id_index ON public.vehicle_maintenance_schedules USING btree (created_user_id);


--
-- Name: vehicle_maintenance_schedules_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_maintenance_schedules_is_active_index ON public.vehicle_maintenance_schedules USING btree (is_active);


--
-- Name: vehicle_maintenance_schedules_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_maintenance_schedules_updated_user_id_index ON public.vehicle_maintenance_schedules USING btree (updated_user_id);


--
-- Name: vehicle_maintenance_schedules_vehicle_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_maintenance_schedules_vehicle_id_index ON public.vehicle_maintenance_schedules USING btree (vehicle_id);


--
-- Name: vehicle_makes_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_makes_created_user_id_index ON public.vehicle_makes USING btree (created_user_id);


--
-- Name: vehicle_makes_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_makes_is_active_index ON public.vehicle_makes USING btree (is_active);


--
-- Name: vehicle_makes_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_makes_updated_user_id_index ON public.vehicle_makes USING btree (updated_user_id);


--
-- Name: vehicle_models_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_models_created_user_id_index ON public.vehicle_models USING btree (created_user_id);


--
-- Name: vehicle_models_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_models_is_active_index ON public.vehicle_models USING btree (is_active);


--
-- Name: vehicle_models_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_models_updated_user_id_index ON public.vehicle_models USING btree (updated_user_id);


--
-- Name: vehicle_owner_types_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_owner_types_created_user_id_index ON public.vehicle_owner_types USING btree (created_user_id);


--
-- Name: vehicle_owner_types_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_owner_types_is_active_index ON public.vehicle_owner_types USING btree (is_active);


--
-- Name: vehicle_owner_types_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_owner_types_updated_user_id_index ON public.vehicle_owner_types USING btree (updated_user_id);


--
-- Name: vehicle_owners_city_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_owners_city_index ON public.vehicle_owners USING btree (city);


--
-- Name: vehicle_owners_country_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_owners_country_id_index ON public.vehicle_owners USING btree (country_id);


--
-- Name: vehicle_owners_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_owners_created_user_id_index ON public.vehicle_owners USING btree (created_user_id);


--
-- Name: vehicle_owners_dob_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_owners_dob_index ON public.vehicle_owners USING btree (dob);


--
-- Name: vehicle_owners_license_expiry_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_owners_license_expiry_index ON public.vehicle_owners USING btree (license_expiry);


--
-- Name: vehicle_owners_license_number_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_owners_license_number_index ON public.vehicle_owners USING btree (license_number);


--
-- Name: vehicle_owners_owner_type_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_owners_owner_type_id_index ON public.vehicle_owners USING btree (owner_type_id);


--
-- Name: vehicle_owners_postal_code_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_owners_postal_code_index ON public.vehicle_owners USING btree (postal_code);


--
-- Name: vehicle_owners_state_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_owners_state_id_index ON public.vehicle_owners USING btree (state_id);


--
-- Name: vehicle_owners_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_owners_updated_user_id_index ON public.vehicle_owners USING btree (updated_user_id);


--
-- Name: vehicle_owners_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_owners_user_id_index ON public.vehicle_owners USING btree (user_id);


--
-- Name: vehicle_pricing_bulk_operations_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_bulk_operations_created_user_id_index ON public.vehicle_pricing_bulk_operations USING btree (created_user_id);


--
-- Name: vehicle_pricing_bulk_operations_initiated_by_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_bulk_operations_initiated_by_index ON public.vehicle_pricing_bulk_operations USING btree (initiated_by);


--
-- Name: vehicle_pricing_bulk_operations_status_operation_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_bulk_operations_status_operation_type_index ON public.vehicle_pricing_bulk_operations USING btree (status, operation_type);


--
-- Name: vehicle_pricing_bulk_operations_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_bulk_operations_updated_user_id_index ON public.vehicle_pricing_bulk_operations USING btree (updated_user_id);


--
-- Name: vehicle_pricing_calculation_definitions_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_calculation_definitions_created_at_index ON public.vehicle_pricing_calculation_definitions USING btree (created_at);


--
-- Name: vehicle_pricing_calculation_definitions_created_by_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_calculation_definitions_created_by_index ON public.vehicle_pricing_calculation_definitions USING btree (created_by);


--
-- Name: vehicle_pricing_calculation_definitions_service_type_id_status_; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_calculation_definitions_service_type_id_status_ ON public.vehicle_pricing_calculation_definitions USING btree (service_type_id, status);


--
-- Name: vehicle_pricing_calculations_booking_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_calculations_booking_id_index ON public.vehicle_pricing_calculations USING btree (booking_id);


--
-- Name: vehicle_pricing_calculations_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_calculations_created_at_index ON public.vehicle_pricing_calculations USING btree (created_at);


--
-- Name: vehicle_pricing_calculations_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_calculations_created_user_id_index ON public.vehicle_pricing_calculations USING btree (created_user_id);


--
-- Name: vehicle_pricing_calculations_slab_definition_id_vehicle_group_i; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_calculations_slab_definition_id_vehicle_group_i ON public.vehicle_pricing_calculations USING btree (slab_definition_id, vehicle_group_id);


--
-- Name: vehicle_pricing_calculations_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_calculations_updated_user_id_index ON public.vehicle_pricing_calculations USING btree (updated_user_id);


--
-- Name: vehicle_pricing_common_rate_definitions_common_rate_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_common_rate_definitions_common_rate_type_index ON public.vehicle_pricing_common_rate_definitions USING btree (common_rate_type);


--
-- Name: vehicle_pricing_common_rate_definitions_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_common_rate_definitions_created_user_id_index ON public.vehicle_pricing_common_rate_definitions USING btree (created_user_id);


--
-- Name: vehicle_pricing_common_rate_definitions_is_active_sort_order_in; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_common_rate_definitions_is_active_sort_order_in ON public.vehicle_pricing_common_rate_definitions USING btree (is_active, sort_order);


--
-- Name: vehicle_pricing_common_rate_definitions_is_mandatory_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_common_rate_definitions_is_mandatory_index ON public.vehicle_pricing_common_rate_definitions USING btree (is_mandatory);


--
-- Name: vehicle_pricing_common_rate_definitions_service_type_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_common_rate_definitions_service_type_id_index ON public.vehicle_pricing_common_rate_definitions USING btree (service_type_id);


--
-- Name: vehicle_pricing_common_rate_definitions_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_common_rate_definitions_updated_user_id_index ON public.vehicle_pricing_common_rate_definitions USING btree (updated_user_id);


--
-- Name: vehicle_pricing_common_rate_definitions_vehicle_group_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_common_rate_definitions_vehicle_group_id_index ON public.vehicle_pricing_common_rate_definitions USING btree (vehicle_group_id);


--
-- Name: vehicle_pricing_history_change_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_history_change_type_index ON public.vehicle_pricing_history USING btree (change_type);


--
-- Name: vehicle_pricing_history_changed_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_history_changed_at_index ON public.vehicle_pricing_history USING btree (changed_at);


--
-- Name: vehicle_pricing_history_changed_by_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_history_changed_by_index ON public.vehicle_pricing_history USING btree (changed_by);


--
-- Name: vehicle_pricing_history_common_rate_definition_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_history_common_rate_definition_id_index ON public.vehicle_pricing_history USING btree (common_rate_definition_id);


--
-- Name: vehicle_pricing_history_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_history_created_user_id_index ON public.vehicle_pricing_history USING btree (created_user_id);


--
-- Name: vehicle_pricing_history_record_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_history_record_type_index ON public.vehicle_pricing_history USING btree (record_type);


--
-- Name: vehicle_pricing_history_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_history_updated_user_id_index ON public.vehicle_pricing_history USING btree (updated_user_id);


--
-- Name: vehicle_pricing_history_vehicle_group_id_service_type_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_history_vehicle_group_id_service_type_id_index ON public.vehicle_pricing_history USING btree (vehicle_group_id, service_type_id);


--
-- Name: vehicle_pricing_notifications_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_notifications_created_at_index ON public.vehicle_pricing_notifications USING btree (created_at);


--
-- Name: vehicle_pricing_notifications_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_notifications_created_user_id_index ON public.vehicle_pricing_notifications USING btree (created_user_id);


--
-- Name: vehicle_pricing_notifications_notification_type_priority_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_notifications_notification_type_priority_index ON public.vehicle_pricing_notifications USING btree (notification_type, priority);


--
-- Name: vehicle_pricing_notifications_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_notifications_updated_user_id_index ON public.vehicle_pricing_notifications USING btree (updated_user_id);


--
-- Name: vehicle_pricing_notifications_user_id_is_read_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_notifications_user_id_is_read_index ON public.vehicle_pricing_notifications USING btree (user_id, is_read);


--
-- Name: vehicle_pricing_slab_definitions_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_slab_definitions_created_user_id_index ON public.vehicle_pricing_slab_definitions USING btree (created_user_id);


--
-- Name: vehicle_pricing_slab_definitions_max_km_per_day_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_slab_definitions_max_km_per_day_index ON public.vehicle_pricing_slab_definitions USING btree (max_km_per_day);


--
-- Name: vehicle_pricing_slab_definitions_max_km_per_package_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_slab_definitions_max_km_per_package_index ON public.vehicle_pricing_slab_definitions USING btree (max_km_per_package);


--
-- Name: vehicle_pricing_slab_definitions_service_type_id_is_active_inde; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_slab_definitions_service_type_id_is_active_inde ON public.vehicle_pricing_slab_definitions USING btree (service_type_id, is_active);


--
-- Name: vehicle_pricing_slab_definitions_sort_order_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_slab_definitions_sort_order_index ON public.vehicle_pricing_slab_definitions USING btree (sort_order);


--
-- Name: vehicle_pricing_slab_definitions_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_slab_definitions_updated_user_id_index ON public.vehicle_pricing_slab_definitions USING btree (updated_user_id);


--
-- Name: vehicle_pricing_slabs_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_slabs_created_user_id_index ON public.vehicle_pricing_slabs USING btree (created_user_id);


--
-- Name: vehicle_pricing_slabs_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_slabs_is_active_index ON public.vehicle_pricing_slabs USING btree (is_active);


--
-- Name: vehicle_pricing_slabs_region_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_slabs_region_id_index ON public.vehicle_pricing_slabs USING btree (region_id);


--
-- Name: vehicle_pricing_slabs_service_type_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_slabs_service_type_id_index ON public.vehicle_pricing_slabs USING btree (service_type_id);


--
-- Name: vehicle_pricing_slabs_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_pricing_slabs_updated_user_id_index ON public.vehicle_pricing_slabs USING btree (updated_user_id);


--
-- Name: vehicle_transmissions_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_transmissions_created_user_id_index ON public.vehicle_transmissions USING btree (created_user_id);


--
-- Name: vehicle_transmissions_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_transmissions_is_active_index ON public.vehicle_transmissions USING btree (is_active);


--
-- Name: vehicle_transmissions_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_transmissions_updated_user_id_index ON public.vehicle_transmissions USING btree (updated_user_id);


--
-- Name: vehicles_company_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicles_company_id_index ON public.vehicles USING btree (company_id);


--
-- Name: vehicles_contract_type_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicles_contract_type_id_index ON public.vehicles USING btree (contract_type_id);


--
-- Name: vehicles_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicles_created_user_id_index ON public.vehicles USING btree (created_user_id);


--
-- Name: vehicles_owner_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicles_owner_id_index ON public.vehicles USING btree (owner_id);


--
-- Name: vehicles_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicles_updated_user_id_index ON public.vehicles USING btree (updated_user_id);


--
-- Name: vehicles_vehicle_group_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicles_vehicle_group_id_index ON public.vehicles USING btree (vehicle_group_id);


--
-- Name: vip_types_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vip_types_created_user_id_index ON public.vip_types USING btree (created_user_id);


--
-- Name: vip_types_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vip_types_is_active_index ON public.vip_types USING btree (is_active);


--
-- Name: vip_types_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vip_types_updated_user_id_index ON public.vip_types USING btree (updated_user_id);


--
-- Name: website_settings_created_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX website_settings_created_user_id_index ON public.website_settings USING btree (created_user_id);


--
-- Name: website_settings_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX website_settings_is_active_index ON public.website_settings USING btree (is_active);


--
-- Name: website_settings_updated_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX website_settings_updated_user_id_index ON public.website_settings USING btree (updated_user_id);


--
-- Name: model_has_permissions model_has_permissions_permission_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.model_has_permissions
    ADD CONSTRAINT model_has_permissions_permission_id_foreign FOREIGN KEY (permission_id) REFERENCES public.permissions(id) ON DELETE CASCADE;


--
-- Name: model_has_roles model_has_roles_role_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.model_has_roles
    ADD CONSTRAINT model_has_roles_role_id_foreign FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: role_has_permissions role_has_permissions_permission_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_has_permissions
    ADD CONSTRAINT role_has_permissions_permission_id_foreign FOREIGN KEY (permission_id) REFERENCES public.permissions(id) ON DELETE CASCADE;


--
-- Name: role_has_permissions role_has_permissions_role_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_has_permissions
    ADD CONSTRAINT role_has_permissions_role_id_foreign FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

--
-- PostgreSQL database dump
--

-- Dumped from database version 17.5
-- Dumped by pg_dump version 17.5

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.migrations (id, migration, batch) FROM stdin;
1	2025_07_05_042439_create_companies_table	1
2	2025_07_05_042439_create_user_role_permission_table	1
3	2025_07_05_042439_create_users_table	1
4	2025_07_05_042440_create_regions_table	1
5	2025_07_05_042441_create_countries_table	1
6	2025_07_05_042442_create_states_table	1
7	2025_07_05_042443_create_currencies_table	1
8	2025_07_05_042443_create_vehicle_classes_table	1
9	2025_07_05_042444_create_vehicle_fuel_types_table	1
10	2025_07_05_042445_create_vehicle_categories_table	1
11	2025_07_05_042445_create_vehicle_transmissions_table	1
12	2025_07_05_042446_create_vehicle_owner_types_table	1
13	2025_07_05_042447_create_vehicle_makes_table	1
14	2025_07_05_042448_create_vehicle_insurance_providers_table	1
15	2025_07_05_042448_create_vehicle_insurance_types_table	1
16	2025_07_05_042448_create_vehicle_owners_table	1
17	2025_07_05_042448_create_vehicles_table	1
18	2025_07_05_042450_create_vehicle_insurances_table	1
19	2025_07_05_042450_create_vehicle_maintenance_records_table	1
20	2025_07_05_042450_create_vehicle_maintenance_schedules_table	1
21	2025_07_05_042450_create_vehicle_pricing_slabs_table	1
22	2025_07_05_042453_create_booking_addons_table	1
23	2025_07_05_042454_create_service_types_table	1
24	2025_07_05_042455_create_payment_transactions_table	1
25	2025_07_05_042456_create_search_saveds_table	1
26	2025_07_05_042456_create_taxi_sessions_table	1
27	2025_07_05_042457_create_driver_logs_table	1
28	2025_07_05_042459_create_agents_table	1
29	2025_07_05_042459_create_drivers_table	1
30	2025_07_05_042459_create_staff_table	1
31	2025_07_05_042460_create_customers_table	1
32	2025_07_05_042500_create_agent_commissions_table	1
33	2025_07_05_042500_create_notification_templates_table	1
34	2025_07_05_042501_create_audit_logs_table	1
35	2025_07_05_042501_create_notification_logs_table	1
36	2025_07_05_042502_create_demand_forecasts_table	1
37	2025_07_05_071707_create_cms_contents_table	1
38	2025_07_05_071709_create_inquiries_table	1
39	2025_07_05_071820_create_cms_content_types_table	1
40	2025_07_07_045544_create_agent_apis_table	1
41	2025_07_07_052106_create_vehicle_models_table	1
42	2025_07_07_090915_create_booking_statuses_table	1
43	2025_07_07_092503_create_vehicle_contract_types_table	1
44	2025_07_07_093154_create_phone_calls_table	1
45	2025_07_07_100133_create_billing_addresses_table	1
46	2025_07_07_100221_create_vip_types_table	1
47	2025_07_07_100448_create_image_galleries_table	1
48	2025_07_07_100647_create_driving_licenses_table	1
49	2025_07_07_100654_create_driving_license_types_table	1
50	2025_07_07_102912_create_booking_channels_table	1
51	2025_07_07_103610_create_agent_api_sessions_table	1
52	2025_07_07_105856_create_vehicle_addons_table	1
53	2025_07_07_110002_create_vehicle_grades_table	1
54	2025_07_07_110140_create_website_settings_table	1
55	2025_07_07_110341_create_business_settings_table	1
56	2025_07_08_040532_create_side_menus_table	1
57	2025_07_08_050536_create_cache_table	1
58	2025_07_08_050609_create_permission_tables	1
59	2025_07_09_072631_create_oauth_auth_codes_table	1
60	2025_07_09_072632_create_oauth_access_tokens_table	1
61	2025_07_09_072633_create_oauth_refresh_tokens_table	1
62	2025_07_09_072634_create_oauth_clients_table	1
63	2025_07_09_072635_create_oauth_device_codes_table	1
64	2025_07_11_032148_create_activity_log_table	1
65	2025_07_11_032149_add_event_column_to_activity_log_table	1
66	2025_07_11_032150_add_batch_uuid_column_to_activity_log_table	1
67	2025_07_11_032338_modify_permission_tables_for_uuid	1
68	2025_07_11_033208_modify_oauth_tables_for_uuid	1
69	2025_07_11_033332_modify_activity_log_for_uuid	1
70	2025_07_11_051622_add_api_permissions	1
71	2025_07_12_032847_create_personal_access_tokens_table	1
72	2025_07_15_091640_add_user_reputation_column	1
73	2025_07_15_091641_create_gamify_tables	1
74	2025_07_15_141506_create_vehicle_discounts_table	1
75	2025_07_15_151837_add_is_active_to_all_tables	1
76	2025_07_16_030817_create_sessions_table	1
77	2025_07_19_024601_create_system_constants_table	1
78	2025_07_23_024604_create_pricing_calculations_table	1
79	2025_07_23_024606_create_vehicle_discounts_table	1
80	2025_07_23_024609_create_vehicle_group_pricing_table	1
81	2025_07_24_024606_create_pricing_slab_definitions_table	1
82	2025_07_24_024609_create_pricing_bulk_operations_table	1
83	2025_07_24_024609_create_pricing_notifications_table	1
84	2025_07_24_024609_create_vehicle_group_common_rate_pricing_table	1
85	2025_07_24_024611_create_pricing_common_rate_definitions_table	1
86	2025_07_24_024611_create_vehicle_pricing_history_table	1
87	2025_07_24_032512_create_vehicle_groups_table	1
88	2025_07_26_create_user_contexts_table	1
89	2025_07_29_070902_create_user_media_table	1
90	2025_08_02_000003_create_booking_approvals_table	1
91	2025_08_02_042473_create_bookings_table	1
92	2025_08_02_064224_add_status_to_vehicles_table	1
93	2025_08_04_044546_create_booking_pricings_table	1
94	2025_08_05_043108_fix_vehicle_models_unique_constraint	1
95	2025_08_05_070001_create_vehicle_pricing_calculation_definitions_table	1
96	2025_08_07_100000_add_km_limits_and_extra_rates_to_pricing_system	1
97	2025_08_09_062130_add_availability_and_assignment_fields_to_vehicles_table	1
98	2025_08_09_062145_add_availability_and_assignment_fields_to_drivers_table	1
99	2025_08_09_062203_create_vehicle_assignments_table	1
100	2025_08_09_062741_create_driver_assignments_table	1
101	2025_08_09_062903_create_vehicle_addon_dependencies_table	1
102	2025_08_11_095633_create_vehicle_companies_table	1
103	2025_08_21_042603_add_billing_type_to_vehicle_addons_table	1
104	2025_08_21_120000_create_booking_variable_customizations_table	1
105	2025_08_25_000001_add_booking_variable_latest_index	1
106	2025_08_26_094557_create_customer_loyalty_points_table	1
107	2025_08_26_094604_create_booking_discounts_table	1
108	2025_08_26_094729_create_loyalty_point_transactions_table	1
109	2025_08_26_094735_create_loyalty_tiers_table	1
110	2025_08_29_042624_add_columns_to_concurrent_management	1
111	2025_09_02_044926_add_concurrent_assignment_fields_to_vehicle_assignments_table	1
112	2025_09_02_044926_allow_concurrent_in_overlap_type_on_vehicle_assignments	1
113	2025_09_09_111357_create_booking_dispatches_table	1
114	2025_09_09_111431_create_booking_qcs_table	1
115	2025_09_09_111509_create_booking_qc_repair_items_table	1
116	2025_09_15_000001_create_booking_status_histories_table	1
117	2025_09_16_000002_add_status_management_fields_to_booking_approvals_table	1
118	2025_09_18_040300_fix_booking_time_columns	1
119	2025_09_22_104509_create_booking_items_table	1
120	2025_09_24_051204_add_vehicle_group_id_to_booking_variable_customizations_table	1
121	2025_10_21_000001_create_booking_searches_table	1
122	2025_10_22_043938_add_view_count_to_booking_searches_table	1
123	2025_10_24_102945_add_category_and_inquiry_fields_to_service_types_table	1
124	2025_10_25_035903_update_service_types_categories_and_inquiry_flags	1
125	2025_11_01_072641_update_cms_content_types_table	2
126	2025_11_01_072647_update_cms_contents_table	2
\.


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.migrations_id_seq', 126, true);


--
-- PostgreSQL database dump complete
--

