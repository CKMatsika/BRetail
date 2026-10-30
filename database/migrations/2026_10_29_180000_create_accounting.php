<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function owned(Blueprint $t): void
    {
        $t->id();
        $t->foreignId('tenant_id')->constrained();
        $t->unique(['tenant_id', 'id']);
    }

    private function fk(Blueprint $t, string $column, string $table, bool $nullable = false): void
    {
        $t->unsignedBigInteger($column)->nullable($nullable);
        $t->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on($table);
    }

    public function up(): void
    {
        Schema::create('accounting_settings', function (Blueprint $t) {
            $this->owned($t);
            $t->unique('tenant_id');
            $t->string('currency', 3);
            $t->boolean('live_enabled')->default(false);
            $t->date('start_date');
            $t->timestamp('configured_at');
            $t->timestamp('live_activated_at')->nullable();
            $t->json('cutover_stock')->nullable();
        });
        Schema::create('chart_accounts', function (Blueprint $t) {
            $this->owned($t);
            $t->string('code', 20);
            $t->string('name', 160);
            $t->string('type', 16);
            $t->string('report_group', 100);
            $t->boolean('is_cash')->default(false);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });
        Schema::create('account_mappings', function (Blueprint $t) {
            $this->owned($t);
            $t->string('role', 40);
            $this->fk($t, 'account_id', 'chart_accounts');
            $t->unique(['tenant_id', 'role']);
        });
        Schema::create('accounting_periods', function (Blueprint $t) {
            $this->owned($t);
            $t->string('name', 100);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->boolean('locked')->default(false);
            $t->timestamp('locked_at')->nullable();
            $this->fk($t, 'locked_by', 'users', true);
            $t->timestamps();
            $t->unique(['tenant_id', 'starts_on']);
        });
        Schema::create('journal_batches', function (Blueprint $t) {
            $this->owned($t);
            $this->fk($t, 'period_id', 'accounting_periods');
            $this->fk($t, 'branch_id', 'branches');
            $this->fk($t, 'actor_id', 'users');
            $t->unsignedBigInteger('sequence');
            $t->string('number', 40);
            $t->string('book', 12);
            $t->string('currency', 3);
            $t->date('date');
            $t->string('reference', 160);
            $t->text('description');
            $t->string('cashflow', 16);
            $t->string('source_type', 40);
            $t->string('source_id', 100);
            $t->string('payload_hash', 64);
            $t->string('status', 16)->default('draft');
            $t->timestamp('created_at');
            $t->timestamp('posted_at')->nullable();
            $t->unique(['tenant_id', 'sequence']);
            $t->unique(['tenant_id', 'book', 'source_type', 'source_id'], 'journal_source_unique');
            $t->index(['tenant_id', 'book', 'date']);
        });
        Schema::create('journal_lines', function (Blueprint $t) {
            $this->owned($t);
            $this->fk($t, 'batch_id', 'journal_batches');
            $this->fk($t, 'account_id', 'chart_accounts');
            $t->decimal('debit', 24, 4);
            $t->decimal('credit', 24, 4);
            $t->string('description', 255)->nullable();
            $t->index(['tenant_id', 'account_id', 'batch_id']);
        });
        Schema::create('posting_links', function (Blueprint $t) {
            $this->owned($t);
            $t->string('book', 12);
            $t->string('source_type', 40);
            $t->string('source_id', 100);
            $t->string('payload_hash', 64);
            $this->fk($t, 'journal_id', 'journal_batches', true);
            $t->timestamp('created_at');
            $t->unique(['tenant_id', 'book', 'source_type', 'source_id'], 'posting_source_unique');
        });
        Schema::create('bank_accounts', function (Blueprint $t) {
            $this->owned($t);
            $this->fk($t, 'account_id', 'chart_accounts');
            $this->fk($t, 'branch_id', 'branches', true);
            $t->string('name', 120);
            $t->string('currency', 3);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'account_id']);
        });
        Schema::create('bank_reconciliations', function (Blueprint $t) {
            $this->owned($t);
            $this->fk($t, 'bank_account_id', 'bank_accounts');
            $t->uuid('request_id');
            $t->string('payload_hash', 64);
            $t->string('book', 12);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->date('baseline_date');
            $t->decimal('opening_balance', 24, 4);
            $t->decimal('closing_balance', 24, 4);
            $t->decimal('ledger_balance', 24, 4)->nullable();
            $t->decimal('outstanding', 24, 4)->nullable();
            $t->string('status', 16)->default('draft');
            $this->fk($t, 'actor_id', 'users');
            $t->timestamp('created_at');
            $t->timestamp('closed_at')->nullable();
            $t->uuid('close_request_id')->nullable();
            $t->unique(['tenant_id', 'request_id']);
        });
        Schema::create('bank_statement_lines', function (Blueprint $t) {
            $this->owned($t);
            $this->fk($t, 'reconciliation_id', 'bank_reconciliations');
            $t->unsignedInteger('position');
            $t->date('date');
            $t->string('reference', 160);
            $t->string('description', 255);
            $t->decimal('amount', 24, 4);
            $t->unique(['reconciliation_id', 'position']);
        });
        Schema::create('bank_matches', function (Blueprint $t) {
            $this->owned($t);
            $this->fk($t, 'statement_line_id', 'bank_statement_lines');
            $this->fk($t, 'journal_line_id', 'journal_lines');
            $t->timestamp('created_at');
            $t->unique('journal_line_id');
        });
        Schema::create('accounting_purchases', function (Blueprint $t) {
            $this->owned($t);
            $this->fk($t, 'warehouse_id', 'warehouses');
            $this->fk($t, 'actor_id', 'users');
            $t->uuid('request_id');
            $t->string('payload_hash', 64);
            $t->string('supplier_name', 160);
            $t->string('invoice_number', 100);
            $t->string('book', 12);
            $t->date('date');
            $t->decimal('net', 24, 4);
            $t->decimal('tax', 24, 4);
            $t->decimal('total', 24, 4);
            $t->string('settlement', 16);
            $t->json('items');
            $t->timestamp('created_at');
            $t->unique(['tenant_id', 'request_id']);
            $t->unique(['tenant_id', 'book', 'supplier_name', 'invoice_number'], 'purchase_invoice_unique');
        });
        Schema::table('pos_sessions', fn (Blueprint $t) => $t->string('mode', 16)->default('training'));
        foreach (DB::table('roles')->get() as $r) {
            $extra = config('accounting.role_permissions.'.$r->name, []);
            if ($extra) {
                DB::table('roles')->where('id', $r->id)->update(['permissions' => json_encode(array_values(array_unique([...json_decode($r->permissions, true), ...$extra])))]);
            }
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION accounting_batch_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p accounting_periods; d numeric; c numeric; n integer;
BEGIN
 IF TG_OP='INSERT' THEN IF NEW.status <> 'draft' THEN RAISE EXCEPTION 'Journals must start as drafts'; END IF; RETURN NEW; END IF;
 IF TG_OP='DELETE' OR OLD.status <> 'draft' OR NEW.status <> 'posted' THEN RAISE EXCEPTION 'Posted journals are immutable'; END IF;
 IF (to_jsonb(NEW)-'status'-'posted_at') IS DISTINCT FROM (to_jsonb(OLD)-'status'-'posted_at') THEN RAISE EXCEPTION 'Posting cannot rewrite journal metadata'; END IF;
 SELECT * INTO p FROM accounting_periods WHERE id=NEW.period_id AND tenant_id=NEW.tenant_id FOR SHARE;
 IF p.locked OR NEW.date < p.starts_on OR NEW.date > p.ends_on THEN RAISE EXCEPTION 'Accounting period is locked or invalid'; END IF;
 SELECT coalesce(sum(debit),0),coalesce(sum(credit),0),count(*) INTO d,c,n FROM journal_lines WHERE batch_id=NEW.id AND tenant_id=NEW.tenant_id;
 IF d<>c OR d<=0 OR n<2 OR NEW.posted_at IS NULL THEN RAISE EXCEPTION 'Journal debits and credits must balance'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER accounting_batch_guard BEFORE INSERT OR UPDATE OR DELETE ON journal_batches FOR EACH ROW EXECUTE FUNCTION accounting_batch_guard();
CREATE OR REPLACE FUNCTION accounting_line_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s text;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Journal lines are immutable'; END IF;
 SELECT status INTO s FROM journal_batches WHERE id=NEW.batch_id AND tenant_id=NEW.tenant_id FOR UPDATE;
 IF s IS DISTINCT FROM 'draft' THEN RAISE EXCEPTION 'Cannot append to a posted journal'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER accounting_line_guard BEFORE INSERT OR UPDATE OR DELETE ON journal_lines FOR EACH ROW EXECUTE FUNCTION accounting_line_guard();
SQL);
            DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT valid_double_entry_line CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))');
            DB::statement("ALTER TABLE journal_batches ADD CONSTRAINT valid_journal_book CHECK (book IN ('live','training'))");
            foreach (['posting_links', 'accounting_purchases', 'bank_statement_lines'] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION inventory_immutable()");
            }
            DB::statement('ALTER TABLE pos_sales DROP CONSTRAINT pos_training_sale_only');
            DB::statement("ALTER TABLE pos_sales ADD CONSTRAINT pos_valid_sale_mode CHECK (mode IN ('training','live') AND total>0 AND tax>=0 AND discount>=0 AND change>=0)");
            DB::statement('ALTER TABLE pos_returns DROP CONSTRAINT pos_training_return_only');
            DB::statement("ALTER TABLE pos_returns ADD CONSTRAINT pos_valid_return_mode CHECK (mode IN ('training','live') AND total>=0 AND net>=0 AND tax>=0)");
        }
    }

    public function down(): void
    {
        if (DB::table('pos_sales')->where('mode', 'live')->exists() || DB::table('journal_batches')->where('status', 'posted')->exists()) {
            throw new RuntimeException('Cannot roll back posted financial data. Restore a reviewed backup to a separate database.');
        }
        if (DB::getDriverName() === 'pgsql') {
            if (DB::table('pos_sales')->where('mode', 'live')->exists() || DB::table('journal_batches')->where('status', 'posted')->exists()) {
                throw new RuntimeException('Cannot roll back posted financial data. Restore a reviewed backup to a separate database.');
            }
            DB::statement('ALTER TABLE pos_sales DROP CONSTRAINT pos_valid_sale_mode');
            DB::statement("ALTER TABLE pos_sales ADD CONSTRAINT pos_training_sale_only CHECK (mode='training' AND total>0)");
            DB::statement('ALTER TABLE pos_returns DROP CONSTRAINT pos_valid_return_mode');
            DB::statement("ALTER TABLE pos_returns ADD CONSTRAINT pos_training_return_only CHECK (mode='training' AND total>=0)");
        }
        Schema::table('pos_sessions', fn (Blueprint $t) => $t->dropColumn('mode'));
        foreach (['accounting_purchases', 'bank_matches', 'bank_statement_lines', 'bank_reconciliations', 'bank_accounts', 'posting_links', 'journal_lines', 'journal_batches', 'accounting_periods', 'account_mappings', 'chart_accounts', 'accounting_settings'] as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS accounting_line_guard()');
            DB::unprepared('DROP FUNCTION IF EXISTS accounting_batch_guard()');
        }
    }
};
