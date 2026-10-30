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
CREATE OR REPLACE FUNCTION accounting_reconciliation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' OR OLD.status<>'draft' THEN RAISE EXCEPTION 'Finalized reconciliations are immutable'; END IF;
 IF NEW.status NOT IN ('closed','cancelled') OR (to_jsonb(NEW)-'status'-'ledger_balance'-'outstanding'-'closed_at'-'close_request_id') IS DISTINCT FROM (to_jsonb(OLD)-'status'-'ledger_balance'-'outstanding'-'closed_at'-'close_request_id') THEN RAISE EXCEPTION 'Only finalization may update a statement'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER accounting_reconciliation_guard BEFORE UPDATE OR DELETE ON bank_reconciliations FOR EACH ROW EXECUTE FUNCTION accounting_reconciliation_guard();
CREATE OR REPLACE FUNCTION accounting_match_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s bigint; r bank_reconciliations; b bank_accounts; l journal_lines; j journal_batches;
BEGIN
 IF TG_OP='UPDATE' THEN RAISE EXCEPTION 'Replace matches using unmatch'; END IF;
 IF TG_OP='DELETE' THEN s=OLD.statement_line_id; ELSE s=NEW.statement_line_id; END IF;
 SELECT br.* INTO r FROM bank_reconciliations br JOIN bank_statement_lines sl ON sl.reconciliation_id=br.id WHERE sl.id=s FOR UPDATE OF br;
 IF r.status IS DISTINCT FROM 'draft' THEN RAISE EXCEPTION 'Only draft statements accept match changes'; END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 SELECT * INTO b FROM bank_accounts WHERE id=r.bank_account_id;
 SELECT * INTO l FROM journal_lines WHERE id=NEW.journal_line_id AND tenant_id=NEW.tenant_id;
 SELECT * INTO j FROM journal_batches WHERE id=l.batch_id;
 IF l.account_id IS DISTINCT FROM b.account_id OR j.book IS DISTINCT FROM r.book OR j.status IS DISTINCT FROM 'posted' OR j.date<r.baseline_date OR j.date>r.ends_on OR (b.branch_id IS NOT NULL AND j.branch_id<>b.branch_id) THEN RAISE EXCEPTION 'Match must use posted entries for this bank and book'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER accounting_match_guard BEFORE INSERT OR UPDATE OR DELETE ON bank_matches FOR EACH ROW EXECUTE FUNCTION accounting_match_guard();
CREATE OR REPLACE FUNCTION accounting_account_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF (NEW.type<>OLD.type OR NEW.is_cash<>OLD.is_cash OR NEW.tenant_id<>OLD.tenant_id) AND EXISTS(SELECT 1 FROM journal_lines WHERE account_id=OLD.id) THEN RAISE EXCEPTION 'Posted account classification is immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER accounting_account_guard BEFORE UPDATE ON chart_accounts FOR EACH ROW EXECUTE FUNCTION accounting_account_guard();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }DB::unprepared('DROP TRIGGER IF EXISTS accounting_reconciliation_guard ON bank_reconciliations; DROP TRIGGER IF EXISTS accounting_match_guard ON bank_matches; DROP TRIGGER IF EXISTS accounting_account_guard ON chart_accounts; DROP FUNCTION IF EXISTS accounting_reconciliation_guard(); DROP FUNCTION IF EXISTS accounting_match_guard(); DROP FUNCTION IF EXISTS accounting_account_guard();');
    }
};
