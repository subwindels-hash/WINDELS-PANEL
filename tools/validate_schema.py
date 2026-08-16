#!/usr/bin/env python3
"""Validate docs/database.sql (generated from the CI3 migrations).

Checks, in migration order:
  1. every statement parses as MySQL
  2. no table is created twice
  3. every FK references a table/column that already exists at that point
  4. every FK column exists on the child table
  5. house rules: InnoDB + utf8mb4, DECIMAL(20,8) money, public_id CHAR(26) UNIQUE,
     UTC DATETIME (no TIMESTAMP), no license/purchase-code artifacts

Usage: python3 tools/validate_schema.py [path/to/database.sql]
"""
import re
import sys
from pathlib import Path

try:
    import sqlglot
    from sqlglot import exp
except ImportError:  # pragma: no cover
    sys.exit("sqlglot is required: pip install sqlglot")

ROOT = Path(__file__).resolve().parent.parent
SQL_PATH = Path(sys.argv[1]) if len(sys.argv) > 1 else ROOT / "docs" / "database.sql"

# Matched on whole underscore-separated tokens, so "dripfeed_order_id" is not
# mistaken for a "fee" column and "rate_limit_per_minute" is not mistaken for money.
MONEY_TOKENS = {
    "balance", "amount", "charge", "rate", "markup", "fee", "bonus",
    "earned", "paid", "spent", "deposited", "refunded", "credited", "commission",
}
MONEY_EXEMPT = {
    ("payment_methods", "fee_percent"),
    ("payment_methods", "bonus_percent"),
    ("referral_accounts", "commission_percent"),
    ("api_keys", "rate_limit_per_minute"),
    ("providers", "rate_multiplier"),
}
BANNED = ("purchase_code", "license_server", "envato", "domain_lock")

errors: list[str] = []
warnings: list[str] = []


def err(msg: str) -> None:
    errors.append(msg)


def warn(msg: str) -> None:
    warnings.append(msg)


def split_statements(sql: str):
    for raw in sqlglot.transpile(sql, read="mysql", write="mysql", pretty=False):
        yield raw


def main() -> int:
    if not SQL_PATH.exists():
        sys.exit(f"missing {SQL_PATH} — run: php tools/export_schema.php")

    sql_text = SQL_PATH.read_text()

    lowered = sql_text.lower()
    for banned in BANNED:
        if banned in lowered:
            err(f"banned licensing artifact present in schema: {banned!r}")

    try:
        statements = sqlglot.parse(sql_text, read="mysql")
    except Exception as exc:  # noqa: BLE001
        sys.exit(f"SQL parse error: {exc}")

    tables: dict[str, set[str]] = {}
    unique_cols: dict[str, set[str]] = {}
    fk_count = 0
    index_count = 0

    for stmt in statements:
        if stmt is None:
            continue

        if isinstance(stmt, exp.Create) and stmt.kind and stmt.kind.upper() == "TABLE":
            name = stmt.this.this.name if isinstance(stmt.this, exp.Schema) else stmt.this.name
            if name in tables:
                err(f"table created twice: {name}")
            cols: set[str] = set()
            uniques: set[str] = set()

            schema = stmt.this
            for cdef in schema.expressions:
                if isinstance(cdef, exp.ColumnDef):
                    cname = cdef.name
                    cols.add(cname)
                    dtype = cdef.args.get("kind")
                    dtype_sql = dtype.sql(dialect="mysql").upper() if dtype else ""
                    check_column(name, cname, dtype_sql, cdef)
                    for c in cdef.constraints:
                        if isinstance(c.kind, (exp.UniqueColumnConstraint, exp.PrimaryKeyColumnConstraint)):
                            uniques.add(cname)
                elif isinstance(cdef, exp.IndexColumnConstraint):
                    index_count += 1

            tables[name] = cols
            unique_cols[name] = uniques
            check_table_options(name, stmt.sql(dialect="mysql"))

        elif isinstance(stmt, exp.Alter):
            target = stmt.this.name if stmt.this else "?"
            if target not in tables:
                err(f"ALTER on unknown table {target}")
            for action in stmt.args.get("actions", []):
                for fk in action.find_all(exp.ForeignKey):
                    fk_count += 1
                    check_fk(target, fk, tables)

    # FKs declared inline inside CREATE TABLE need the raw text (sqlglot keeps them
    # as ForeignKey nodes with a Reference); re-walk with table context.
    seen: set[str] = set()
    for stmt in statements:  # noqa: PLR1704
        if isinstance(stmt, exp.Create) and stmt.kind and stmt.kind.upper() == "TABLE":
            name = stmt.this.this.name if isinstance(stmt.this, exp.Schema) else stmt.this.name
            seen.add(name)
            for fk in stmt.find_all(exp.ForeignKey):
                fk_count += 1
                check_fk(name, fk, {t: c for t, c in tables.items() if t in seen})

    print(f"parsed {len(statements)} statements · {len(tables)} tables · {fk_count} foreign keys")

    required = {
        "users", "wallets", "wallet_transactions", "ledger_entries", "idempotency_keys",
        "services", "service_categories", "providers", "orders", "order_status_history",
        "payment_transactions", "payment_webhooks", "tickets", "settings", "api_keys",
        "audit_logs", "feature_flags", "currencies",
    }
    missing = sorted(required - set(tables))
    if missing:
        err(f"missing required tables: {', '.join(missing)}")

    for msg in warnings:
        print(f"  warn  {msg}")
    for msg in errors:
        print(f"  ERROR {msg}")

    if errors:
        print(f"\nFAILED — {len(errors)} error(s)")
        return 1
    print(f"OK — schema valid ({len(warnings)} warning(s))")
    return 0


def check_column(table: str, col: str, dtype: str, cdef) -> None:
    if "TIMESTAMP" in dtype:
        err(f"{table}.{col} uses TIMESTAMP — use DATETIME (UTC) instead")

    tokens = set(col.split("_"))
    is_money = bool(tokens & MONEY_TOKENS) and not col.endswith("_id") and not col.endswith("_at")
    if is_money and (table, col) not in MONEY_EXEMPT and not col.endswith("_percent"):
        if not re.search(r"DECIMAL\(20,\s*8\)", dtype):
            err(f"{table}.{col} looks monetary but is {dtype or '?'} — expected DECIMAL(20,8)")
    if col.endswith("_percent") and "DECIMAL" not in dtype:
        err(f"{table}.{col} should be DECIMAL, got {dtype}")

    if col == "public_id":
        if "CHAR(26)" not in dtype.replace(" ", ""):
            err(f"{table}.public_id should be CHAR(26) (ULID), got {dtype}")
        has_unique = any(isinstance(c.kind, exp.UniqueColumnConstraint) for c in cdef.constraints)
        if not has_unique:
            err(f"{table}.public_id must be UNIQUE")


def check_table_options(table: str, sql: str) -> None:
    upper = sql.upper()
    if "ENGINE=INNODB" not in upper.replace(" =", "=").replace("= ", "="):
        err(f"{table} is not ENGINE=InnoDB")
    if "UTF8MB4" not in upper:
        err(f"{table} is not utf8mb4")


def check_fk(child: str, fk, tables: dict) -> None:
    ref = fk.args.get("reference")
    if ref is None:
        return
    schema = ref.this
    parent = schema.this.name if isinstance(schema, exp.Schema) else schema.name
    parent_cols = [c.name for c in schema.expressions] if isinstance(schema, exp.Schema) else []
    child_cols = [c.name for c in fk.expressions]

    if parent not in tables:
        err(f"FK {child} -> {parent}: parent table not defined yet (ordering problem)")
        return
    for c in child_cols:
        if c not in tables.get(child, set()):
            err(f"FK {child}.{c}: column does not exist on {child}")
    for c in parent_cols:
        if c not in tables.get(parent, set()):
            err(f"FK {child} -> {parent}.{c}: referenced column does not exist")


if __name__ == "__main__":
    raise SystemExit(main())
