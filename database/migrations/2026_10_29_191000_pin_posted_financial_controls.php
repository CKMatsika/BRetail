<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION accounting_post_controls() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.status='posted' THEN
  IF EXISTS(SELECT 1 FROM journal_lines l JOIN chart_accounts a ON a.id=l.account_id WHERE l.batch_id=NEW.id AND (NOT a.active OR (a.is_cash AND (l.debit<>round(l.debit,2) OR l.credit<>round(l.credit,2))))) THEN RAISE EXCEPTION 'Invalid posted account or cash precision'; END IF;
  IF EXISTS(SELECT 1 FROM journal_lines l JOIN bank_accounts b ON b.account_id=l.account_id WHERE l.batch_id=NEW.id AND ((b.branch_id IS NOT NULL AND b.branch_id<>NEW.branch_id) OR EXISTS(SELECT 1 FROM bank_reconciliations r WHERE r.bank_account_id=b.id AND r.book=NEW.book AND r.status='closed' AND NEW.date<=r.ends_on))) THEN RAISE EXCEPTION 'Bank posting violates branch or reconciled cutoff'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER accounting_post_controls BEFORE UPDATE ON journal_batches FOR EACH ROW EXECUTE FUNCTION accounting_post_controls();
CREATE OR REPLACE FUNCTION accounting_period_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.locked THEN RAISE EXCEPTION 'Locked periods cannot be changed'; END IF;
 IF (NEW.tenant_id<>OLD.tenant_id OR NEW.starts_on<>OLD.starts_on OR NEW.ends_on<>OLD.ends_on) AND EXISTS(SELECT 1 FROM journal_batches WHERE period_id=OLD.id) THEN RAISE EXCEPTION 'Used period boundaries are immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER accounting_period_guard BEFORE UPDATE ON accounting_periods FOR EACH ROW EXECUTE FUNCTION accounting_period_guard();
CREATE OR REPLACE FUNCTION accounting_statement_append_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s text;
BEGIN
 SELECT status INTO s FROM bank_reconciliations WHERE id=NEW.reconciliation_id AND tenant_id=NEW.tenant_id FOR UPDATE;
 IF s IS DISTINCT FROM 'draft' THEN RAISE EXCEPTION 'Cannot append to a finalized statement'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER accounting_statement_append_guard BEFORE INSERT ON bank_statement_lines FOR EACH ROW EXECUTE FUNCTION accounting_statement_append_guard();
CREATE OR REPLACE FUNCTION pos_session_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' OR OLD.status<>'open' OR NEW.status<>'closed' OR NEW.tenant_id<>OLD.tenant_id OR NEW.warehouse_id<>OLD.warehouse_id OR NEW.user_id<>OLD.user_id OR NEW.opening_cash<>OLD.opening_cash OR NEW.currency<>OLD.currency OR NEW.request_id<>OLD.request_id OR NEW.payload_hash<>OLD.payload_hash OR NEW.mode<>OLD.mode THEN RAISE EXCEPTION 'A cashier shift can only be closed once; its opening identity and book are immutable'; END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }DB::unprepared('DROP TRIGGER IF EXISTS accounting_post_controls ON journal_batches; DROP TRIGGER IF EXISTS accounting_period_guard ON accounting_periods; DROP TRIGGER IF EXISTS accounting_statement_append_guard ON bank_statement_lines; DROP FUNCTION IF EXISTS accounting_post_controls(); DROP FUNCTION IF EXISTS accounting_period_guard(); DROP FUNCTION IF EXISTS accounting_statement_append_guard();');
    }
};
