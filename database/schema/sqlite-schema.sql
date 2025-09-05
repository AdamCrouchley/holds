CREATE TABLE IF NOT EXISTS "migrations"(
  "id" integer primary key autoincrement not null,
  "migration" varchar not null,
  "batch" integer not null
);
CREATE TABLE IF NOT EXISTS "users"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "email" varchar not null,
  "email_verified_at" datetime,
  "password" varchar not null,
  "remember_token" varchar,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "users_email_unique" on "users"("email");
CREATE TABLE IF NOT EXISTS "password_reset_tokens"(
  "email" varchar not null,
  "token" varchar not null,
  "created_at" datetime,
  primary key("email")
);
CREATE TABLE IF NOT EXISTS "sessions"(
  "id" varchar not null,
  "user_id" integer,
  "ip_address" varchar,
  "user_agent" text,
  "payload" text not null,
  "last_activity" integer not null,
  primary key("id")
);
CREATE INDEX "sessions_user_id_index" on "sessions"("user_id");
CREATE INDEX "sessions_last_activity_index" on "sessions"("last_activity");
CREATE TABLE IF NOT EXISTS "cache"(
  "key" varchar not null,
  "value" text not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE TABLE IF NOT EXISTS "cache_locks"(
  "key" varchar not null,
  "owner" varchar not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE TABLE IF NOT EXISTS "customers"(
  "id" integer primary key autoincrement not null,
  "first_name" varchar,
  "last_name" varchar,
  "email" varchar not null,
  "phone" varchar,
  "stripe_customer_id" varchar,
  "default_payment_method_id" varchar,
  "meta" text,
  "created_at" datetime,
  "updated_at" datetime,
  "password" varchar,
  "remember_token" varchar,
  "email_verified_at" datetime,
  "portal_token" varchar,
  "login_token" varchar,
  "login_token_expires_at" datetime,
  "portal_last_login_at" datetime,
  "portal_last_seen_at" datetime,
  "portal_timezone" varchar,
  "portal_magic_redirect" varchar
);
CREATE INDEX "customers_email_index" on "customers"("email");
CREATE INDEX "customers_stripe_customer_id_index" on "customers"(
  "stripe_customer_id"
);
CREATE INDEX "customers_default_payment_method_id_index" on "customers"(
  "default_payment_method_id"
);
CREATE TABLE IF NOT EXISTS "bookings"(
  "id" integer primary key autoincrement not null,
  "customer_id" integer not null,
  "reference" varchar not null,
  "vehicle" varchar,
  "start_at" datetime not null,
  "end_at" datetime not null,
  "total_amount" integer not null,
  "deposit_amount" integer not null,
  "hold_amount" integer not null default '150000',
  "currency" varchar not null default 'NZD',
  "balance_charged" tinyint(1) not null default '0',
  "portal_token" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  "meta" text,
  "external_source" varchar,
  "external_id" varchar,
  "stripe_customer_id" varchar,
  "stripe_payment_method_id" varchar,
  "stripe_payment_intent_id" varchar,
  "stripe_setup_intent_id" varchar,
  "stripe_charge_id" varchar,
  "stripe_authorized_amount_cents" integer not null default '0',
  "stripe_captured_amount_cents" integer not null default '0',
  "stripe_currency" varchar,
  "stripe_status" varchar,
  "stripe_last_error" text,
  "stripe_last_event_at" datetime,
  "stripe_payload" text,
  "status" varchar default 'pending',
  "vehicle_id" integer,
  "stripe_bond_pi_id" varchar,
  "bond_authorized_at" datetime,
  "bond_captured_at" datetime,
  "bond_released_at" datetime,
  "paid_amount_cents" integer not null default '0'
);
CREATE UNIQUE INDEX "bookings_reference_unique" on "bookings"("reference");
CREATE UNIQUE INDEX "bookings_portal_token_unique" on "bookings"(
  "portal_token"
);
CREATE TABLE IF NOT EXISTS "payments"(
  "id" integer primary key autoincrement not null,
  "booking_id" integer not null,
  "customer_id" integer not null,
  "type" varchar check("type" in('booking_deposit', 'balance', 'extra', 'refund')) not null,
  "amount" integer not null,
  "currency" varchar not null default 'NZD',
  "stripe_payment_intent_id" varchar,
  "stripe_charge_id" varchar,
  "status" varchar check("status" in('pending', 'succeeded', 'failed', 'canceled')) not null default 'pending',
  "details" text,
  "created_at" datetime,
  "updated_at" datetime,
  "mechanism" varchar,
  "stripe_payment_method_id" varchar
);
CREATE INDEX "payments_type_index" on "payments"("type");
CREATE INDEX "payments_stripe_payment_intent_id_index" on "payments"(
  "stripe_payment_intent_id"
);
CREATE INDEX "payments_stripe_charge_id_index" on "payments"(
  "stripe_charge_id"
);
CREATE INDEX "payments_status_index" on "payments"("status");
CREATE UNIQUE INDEX "customers_email_unique" on "customers"("email");
CREATE TABLE IF NOT EXISTS "deposits"(
  "id" integer primary key autoincrement not null,
  "booking_id" integer not null,
  "customer_id" integer not null,
  "amount" integer not null,
  "currency" varchar not null default('NZD'),
  "stripe_payment_intent_id" varchar,
  "status" varchar not null,
  "authorised_at" datetime,
  "expires_at" datetime,
  "meta" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("customer_id") references customers("id") on delete cascade on update no action,
  foreign key("booking_id") references bookings("id") on delete cascade on update no action
);
CREATE INDEX "bookings_external_source_index" on "bookings"("external_source");
CREATE INDEX "bookings_external_id_index" on "bookings"("external_id");
CREATE TABLE IF NOT EXISTS "flows"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "description" text,
  "hold_amount_cents" integer not null default '0',
  "auto_renew_days" integer not null default '7',
  "auto_release_days" integer not null default '3',
  "allow_partial_capture" tinyint(1) not null default '1',
  "auto_capture_on_damage" tinyint(1) not null default '1',
  "auto_cancel_if_no_capture" tinyint(1) not null default '1',
  "auto_cancel_after_days" integer not null default '14',
  "required_fields" text,
  "comms" text,
  "webhooks" text,
  "tags" text,
  "created_by" integer,
  "updated_by" integer,
  "deleted_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "slug" varchar
);
CREATE INDEX "flows_name_index" on "flows"("name");
CREATE INDEX "bookings_stripe_customer_id_index" on "bookings"(
  "stripe_customer_id"
);
CREATE INDEX "bookings_stripe_payment_method_id_index" on "bookings"(
  "stripe_payment_method_id"
);
CREATE INDEX "bookings_stripe_payment_intent_id_index" on "bookings"(
  "stripe_payment_intent_id"
);
CREATE INDEX "bookings_stripe_setup_intent_id_index" on "bookings"(
  "stripe_setup_intent_id"
);
CREATE INDEX "bookings_stripe_charge_id_index" on "bookings"(
  "stripe_charge_id"
);
CREATE INDEX "bookings_stripe_status_index" on "bookings"("stripe_status");
CREATE INDEX "customers_portal_token_index" on "customers"("portal_token");
CREATE INDEX "customers_login_token_index" on "customers"("login_token");
CREATE INDEX "customers_login_token_expires_at_index" on "customers"(
  "login_token_expires_at"
);
CREATE INDEX "customers_portal_last_login_at_index" on "customers"(
  "portal_last_login_at"
);
CREATE INDEX "customers_portal_last_seen_at_index" on "customers"(
  "portal_last_seen_at"
);
CREATE INDEX "payments_mechanism_index" on "payments"("mechanism");
CREATE INDEX "payments_booking_id_index" on "payments"("booking_id");
CREATE INDEX "payments_customer_id_index" on "payments"("customer_id");
CREATE INDEX "payments_created_at_index" on "payments"("created_at");
CREATE UNIQUE INDEX "flows_slug_unique" on "flows"("slug");
CREATE INDEX "bookings_vehicle_id_index" on "bookings"("vehicle_id");
CREATE INDEX "bookings_stripe_bond_pi_id_index" on "bookings"(
  "stripe_bond_pi_id"
);
CREATE INDEX "bookings_bond_authorized_at_index" on "bookings"(
  "bond_authorized_at"
);
CREATE INDEX "bookings_bond_captured_at_index" on "bookings"(
  "bond_captured_at"
);
CREATE INDEX "bookings_bond_released_at_index" on "bookings"(
  "bond_released_at"
);
CREATE INDEX "payments_stripe_payment_method_id_index" on "payments"(
  "stripe_payment_method_id"
);
CREATE INDEX "bookings_paid_amount_cents_index" on "bookings"(
  "paid_amount_cents"
);
CREATE TABLE IF NOT EXISTS "api_clients"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "contact_email" varchar,
  "is_active" tinyint(1) not null default '1',
  "scopes" text,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime
);
CREATE INDEX "api_clients_name_index" on "api_clients"("name");
CREATE TABLE IF NOT EXISTS "automation_settings"(
  "id" integer primary key autoincrement not null,
  "key" varchar not null,
  "value" text,
  "brand" varchar,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "automation_settings_key_index" on "automation_settings"("key");
CREATE INDEX "automation_settings_brand_index" on "automation_settings"(
  "brand"
);

INSERT INTO migrations VALUES(1,'0001_01_01_000000_create_users_table',1);
INSERT INTO migrations VALUES(2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO migrations VALUES(3,'2025_08_26_080202_create_bookings_table',1);
INSERT INTO migrations VALUES(4,'2025_08_26_080202_create_customers_table',1);
INSERT INTO migrations VALUES(5,'2025_08_26_080202_create_payments_table',1);
INSERT INTO migrations VALUES(6,'2025_08_26_081020_alter_customers_table',1);
INSERT INTO migrations VALUES(7,'2025_08_26_081023_create_deposits_table',1);
INSERT INTO migrations VALUES(8,'2025_08_26_084755_add_portal_token_to_bookings_table',1);
INSERT INTO migrations VALUES(9,'2025_08_26_084755_add_portal_token_to_customers',1);
INSERT INTO migrations VALUES(10,'2025_08_26_085826_add_name_fields_to_customers_table',1);
INSERT INTO migrations VALUES(11,'2025_08_26_092024_add_customer_and_fields_to_bookings_table',1);
INSERT INTO migrations VALUES(12,'2025_08_26_092230_create_bookings_table_full',1);
INSERT INTO migrations VALUES(13,'2025_08_26_092230_create_customers_table_full',1);
INSERT INTO migrations VALUES(14,'2025_08_26_092859_rebuild_bookings_table',1);
INSERT INTO migrations VALUES(15,'2025_08_26_094802_add_stripe_ids_to_payments_table',1);
INSERT INTO migrations VALUES(16,'2025_08_26_095116_rebuild_payments_table',1);
INSERT INTO migrations VALUES(17,'2025_08_27_084424_add_unique_constraints_to_bookings_and_customers',1);
INSERT INTO migrations VALUES(18,'2025_08_27_203122_add_first_last_name_and_meta_to_customers',1);
INSERT INTO migrations VALUES(19,'2025_08_28_000002_add_meta_to_bookings',1);
INSERT INTO migrations VALUES(20,'2025_08_28_001512_enforce_booking_id_on_deposits',1);
INSERT INTO migrations VALUES(21,'2025_08_28_002358_add_auth_columns_to_customers_table',1);
INSERT INTO migrations VALUES(22,'2025_08_28_002358_add_booking_id_to_deposits',1);
INSERT INTO migrations VALUES(23,'2025_08_28_002358_add_external_keys_to_bookings',1);
INSERT INTO migrations VALUES(24,'2025_08_28_002358_add_stripe_columns_to_customers_and_payments',1);
INSERT INTO migrations VALUES(25,'2025_08_28_002358_create_flows_if_missing',1);
INSERT INTO migrations VALUES(26,'2025_08_28_025704_add_booking_id_to_deposits_sqlite',1);
INSERT INTO migrations VALUES(27,'2025_08_28_025704_add_password_to_customers_table',1);
INSERT INTO migrations VALUES(28,'2025_08_28_025704_add_stripe_cols_to_bookings',1);
INSERT INTO migrations VALUES(29,'2025_08_28_032550_add_status_and_meta_to_bookings',1);
INSERT INTO migrations VALUES(30,'2025_08_30_004632_add_portal_token_to_customers_table',1);
INSERT INTO migrations VALUES(31,'2025_08_30_020510_add_magic_link_columns_to_customers_table',1);
INSERT INTO migrations VALUES(32,'2025_08_31_024253_add_mechanism_to_payments_table',1);
INSERT INTO migrations VALUES(33,'2025_08_31_035117_add_payment_indexes',1);
INSERT INTO migrations VALUES(34,'2025_08_31_035117_add_slug_to_flows_table',1);
INSERT INTO migrations VALUES(35,'2025_08_31_212544_add_portal_token_to_bookings_table',1);
INSERT INTO migrations VALUES(36,'2025_08_31_212544_add_vehicle_id_to_bookings_table',1);
INSERT INTO migrations VALUES(37,'2025_09_01_123456_add_bond_columns_to_bookings_table',1);
INSERT INTO migrations VALUES(38,'2025_09_01_123456_add_bond_tracking_columns_to_bookings_table',1);
INSERT INTO migrations VALUES(39,'2025_09_01_123456_add_stripe_columns_to_payments_table',1);
INSERT INTO migrations VALUES(40,'2025_09_02_000000_add_paid_amount_to_bookings_table',1);
INSERT INTO migrations VALUES(41,'2025_09_02_000000_add_unique_index_to_bookings_portal_token',1);
INSERT INTO migrations VALUES(42,'2025_09_02_000000_create_api_clients_table',1);
INSERT INTO migrations VALUES(43,'2025_09_02_000000_create_automation_settings_table',1);
