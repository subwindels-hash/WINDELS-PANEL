#!/usr/bin/env python3
"""Validate database/production.sql — the file cPanel operators import.

`tools/validate_schema.py` already checks the schema house rules. This script
checks the thing that is new about production.sql: that it is a *complete and
self-consistent database*, because after it is imported nothing else runs. A
missing column, a seed row that points at an id which is never inserted, or a
NOT NULL column with no value and no default is not a warning here — it is a
panel that 500s on its first page view with no terminal available to fix it.

Checks:
  1. every statement parses as MySQL (sqlglot)
  2. no table is created twice; every ALTER/INSERT targets a known table
  3. every foreign key points at a table+column that exists
  4. every INSERT names columns that exist, and supplies a value for every
     NOT NULL column that has no default
  5. every foreign-key *value* in the seed data resolves to a row that the file
     itself inserts
  6. the deployment invariants: migration bookkeeping present and matching the
     migrations on disk, a SUPER_ADMIN user with a bcrypt hash, a wallet for
     that user, roles/permissions/settings/feature flags non-empty
  7. the whole file replays into SQLite (transpiled) and the expected rows come
     back out — a cheap end-to-end proof that the INSERTs actually load

Usage: python3 tools/validate_production_sql.py [path/to/production.sql]
"""
import re
import sqlite3
import sys
from pathlib import Path

try:
    import sqlglot
    from sqlglot import exp
except ImportError:  # pragma: no cover
    sys.exit("sqlglot is required: pip install sqlglot")

ROOT = Path(__file__).resolve().parent.parent
SQL_PATH = Path(sys.argv[1]) if len(sys.argv) > 1 else ROOT / "database" / "production.sql"

errors: list[str] = []
notes: list[str] = []


def fail(msg: str) -> None:
    errors.append(msg)


def split_statements(sql: str) -> list[str]:
    """Split on semicolons that are not inside a string literal."""
    out, buf, quote = [], [], None
    i = 0
    while i < len(sql):
        ch = sql[i]
        if quote:
            buf.append(ch)
            if ch == "\\":
                if i + 1 < len(sql):
                    buf.append(sql[i + 1])
                    i += 2
                    continue
            elif ch == quote:
                quote = None
        else:
            if ch in ("'", '"', "`"):
                quote = ch
                buf.append(ch)
            elif ch == "-" and sql[i:i + 2] == "--":
                end = sql.find("\n", i)
                i = len(sql) if end == -1 else end
                continue
            elif ch == ";":
                stmt = "".join(buf).strip()
                if stmt:
                    out.append(stmt)
                buf = []
            else:
                buf.append(ch)
        i += 1
    tail = "".join(buf).strip()
    if tail:
        out.append(tail)
    return out


def main() -> int:
    if not SQL_PATH.exists():
        sys.exit(f"{SQL_PATH} does not exist — run: php tools/build_production_sql.php")

    raw = SQL_PATH.read_text()
    statements = split_statements(raw)
    notes.append(f"{len(statements)} statements")

    # table -> {column: {"nullable": bool, "has_default": bool}}
    tables: dict[str, dict] = {}
    # table -> {column: [(value, ...)]} captured seed rows, keyed by column name
    inserted: dict[str, list[dict]] = {}
    foreign_keys: list[tuple] = []
    insert_count = 0

    for stmt in statements:
        head = stmt.lstrip().upper()
        if head.startswith(("SET ", "DELETE FROM MIGRATIONS")):
            continue

        try:
            parsed = sqlglot.parse_one(stmt, read="mysql")
        except Exception as exc:  # noqa: BLE001
            fail(f"unparseable statement: {exc}\n    {stmt.splitlines()[0][:120]}")
            continue

        if isinstance(parsed, exp.Create) and parsed.args.get("kind", "").upper() == "TABLE":
            name = parsed.this.this.name if isinstance(parsed.this, exp.Schema) else parsed.this.name
            if name in tables:
                fail(f"table {name} is created twice")
            cols = {}
            for coldef in parsed.find_all(exp.ColumnDef):
                nullable = True
                has_default = False
                for c in coldef.args.get("constraints") or []:
                    kind = c.args.get("kind")
                    if isinstance(kind, exp.NotNullColumnConstraint):
                        # sqlglot models an explicit `NULL` as the same node
                        # with allow_null set, so check the flag rather than
                        # the class.
                        nullable = bool(kind.args.get("allow_null"))
                    if isinstance(kind, (exp.DefaultColumnConstraint, exp.AutoIncrementColumnConstraint,
                                         exp.GeneratedAsIdentityColumnConstraint,
                                         exp.PrimaryKeyColumnConstraint)):
                        has_default = True
                cols[coldef.name] = {"nullable": nullable, "has_default": has_default}
            tables[name] = cols

            for fk in parsed.find_all(exp.ForeignKey):
                ref = fk.args.get("reference")
                if ref is None:
                    continue
                ref_table = ref.find(exp.Table)
                ref_cols = [i.name for i in ref.find_all(exp.Identifier)]
                child_cols = [e.name for e in fk.expressions]
                if ref_table is not None:
                    foreign_keys.append((name, child_cols, ref_table.name,
                                         [c for c in ref_cols if c != ref_table.name]))

        elif isinstance(parsed, exp.Alter):
            target = parsed.this.name if parsed.this else None
            if target and target not in tables:
                fail(f"ALTER TABLE {target} before it is created")
            for fk in parsed.find_all(exp.ForeignKey):
                ref = fk.args.get("reference")
                if ref is None:
                    continue
                ref_table = ref.find(exp.Table)
                ref_cols = [i.name for i in ref.find_all(exp.Identifier)]
                child_cols = [e.name for e in fk.expressions]
                if ref_table is not None:
                    foreign_keys.append((target, child_cols, ref_table.name,
                                         [c for c in ref_cols if c != ref_table.name]))
            # ALTER ... MODIFY may retype a column; keep the column set intact.

        elif isinstance(parsed, exp.Insert):
            insert_count += 1
            table_node = parsed.find(exp.Table)
            table = table_node.name if table_node else None
            if table not in tables:
                fail(f"INSERT into unknown table {table}")
                continue
            schema_node = parsed.this if isinstance(parsed.this, exp.Schema) else None
            columns = [c.name for c in schema_node.expressions] if schema_node else []
            unknown = [c for c in columns if c not in tables[table]]
            if unknown:
                fail(f"INSERT into {table} names columns that do not exist: {', '.join(unknown)}")
                continue
            required = [
                c for c, meta in tables[table].items()
                if not meta["nullable"] and not meta["has_default"] and c not in columns
            ]
            if required:
                fail(f"INSERT into {table} omits NOT NULL columns with no default: {', '.join(required)}")

            values = parsed.expression
            rows = values.expressions if isinstance(values, exp.Values) else []
            for row in rows:
                literals = []
                for cell in row.expressions:
                    if isinstance(cell, exp.Literal):
                        literals.append(cell.this)
                    elif isinstance(cell, exp.Null):
                        literals.append(None)
                    elif isinstance(cell, exp.Neg):
                        literals.append("-" + str(cell.this.this))
                    else:
                        literals.append(cell.sql())
                inserted.setdefault(table, []).append(dict(zip(columns, literals)))

    # 3. foreign keys resolve structurally
    for child, child_cols, parent, parent_cols in foreign_keys:
        if parent not in tables:
            fail(f"foreign key {child}({','.join(child_cols)}) references unknown table {parent}")
            continue
        for col in child_cols:
            if col not in tables.get(child, {}):
                fail(f"foreign key on {child} uses unknown column {col}")
        for col in parent_cols:
            if col and col not in tables[parent]:
                fail(f"foreign key {child} -> {parent}({col}) references unknown column")

    # 5. foreign-key values in seed data resolve to inserted rows
    parent_ids = {t: {str(r.get("id")) for r in rows if r.get("id") is not None}
                  for t, rows in inserted.items()}
    for child, child_cols, parent, parent_cols in foreign_keys:
        if len(child_cols) != 1 or parent_cols not in ([], ["id"]):
            continue
        col = child_cols[0]
        for row in inserted.get(child, []):
            value = row.get(col)
            if value in (None, "NULL"):
                continue
            if parent in parent_ids and str(value) not in parent_ids[parent]:
                fail(f"{child}.{col}={value} has no matching {parent}.id in the seed data")

    # 6. deployment invariants
    migration_files = sorted((ROOT / "application" / "migrations").glob("*.php"))
    expected_version = max(int(p.name[:3]) for p in migration_files)
    m = re.search(r"INSERT INTO migrations \(version\) VALUES \((\d+)\)", raw)
    if not m:
        fail("no migrations bookkeeping row — `php index.php migrate` would replay every migration")
    elif int(m.group(1)) != expected_version:
        fail(f"migrations row is {m.group(1)} but application/migrations has {expected_version}")
    else:
        notes.append(f"migration bookkeeping pinned at version {expected_version}")

    users = inserted.get("users", [])
    admins = [u for u in users if u.get("role") == "SUPER_ADMIN"]
    if not admins:
        fail("no SUPER_ADMIN user — the panel could not be administered after import")
    for admin in admins:
        if not str(admin.get("password_hash", "")).startswith("$2"):
            fail("the administrator password_hash is not a bcrypt hash")
        if admin.get("status") != "ACTIVE":
            fail("the administrator account is not ACTIVE")
        if not admin.get("email_verified_at"):
            fail("the administrator email is not marked verified — login would demand verification")
    admin_ids = {str(a.get("id")) for a in admins}
    wallet_users = {str(w.get("user_id")) for w in inserted.get("wallets", [])}
    if admin_ids and not admin_ids <= wallet_users:
        fail("the administrator has no wallet row")

    for table, minimum in (("roles", 4), ("permissions", 20), ("role_permissions", 40),
                           ("settings", 20), ("feature_flags", 5), ("payment_methods", 1),
                           ("email_templates", 4), ("currencies", 1), ("price_groups", 1)):
        got = len(inserted.get(table, []))
        if got < minimum:
            fail(f"{table} has {got} seeded rows, expected at least {minimum}")

    if not any(c.get("is_base") == "1" for c in inserted.get("currencies", [])):
        fail("no base currency row in currencies")

    notes.append(f"{insert_count} INSERT statements covering {len(inserted)} tables")
    notes.append(f"{len(tables)} tables, {len(foreign_keys)} foreign keys")

    # 7. replay into SQLite
    replay_errors = replay_sqlite(statements, tables)
    errors.extend(replay_errors)

    try:
        shown = SQL_PATH.relative_to(ROOT)
    except ValueError:
        shown = SQL_PATH
    print(f"validating {shown}")
    for note in notes:
        print(f"  · {note}")
    if errors:
        print(f"\n{len(errors)} problem(s):")
        for e in errors:
            print(f"  ✗ {e}")
        return 1
    print("\nOK — production.sql is complete and self-consistent.")
    return 0


def replay_sqlite(statements: list[str], tables: dict) -> list[str]:
    """Replay the file's INSERTs into SQLite and query the result.

    The tables are rebuilt from the parsed CREATE TABLEs rather than
    transpiled, because MySQL's inline INDEX/ENGINE/COMMENT syntax has no
    SQLite equivalent and translating it would test sqlglot, not the file.
    What this does test is the part that matters after an import: that every
    INSERT lines up with the table it targets, that NOT NULL columns really do
    receive values, and that the seeded rows can be joined back together
    (roles to permissions, users to wallets) the way the application will.
    """
    problems: list[str] = []
    con = sqlite3.connect(":memory:")
    for name, cols in tables.items():
        if not cols:
            continue
        defs = []
        for col, meta in cols.items():
            piece = f'"{col}"'
            if not meta["nullable"] and not meta["has_default"]:
                piece += " NOT NULL"
            defs.append(piece)
        con.execute(f'CREATE TABLE "{name}" ({", ".join(defs)})')

    executed = 0
    for stmt in statements:
        if not stmt.lstrip().upper().startswith("INSERT INTO"):
            continue
        try:
            converted = sqlglot.transpile(stmt, read="mysql", write="sqlite")[0]
        except Exception as exc:  # noqa: BLE001
            problems.append(f"could not transpile for replay: {exc}")
            continue
        try:
            con.execute(converted)
            executed += 1
        except sqlite3.Error as exc:
            problems.append(f"replay failed: {exc}\n    {converted[:160]}")
    con.commit()

    if not problems:
        checks = {
            "roles": "SELECT COUNT(*) FROM roles",
            "permissions": "SELECT COUNT(*) FROM permissions",
            "settings": "SELECT COUNT(*) FROM settings",
            "active admin": "SELECT COUNT(*) FROM users WHERE role = 'SUPER_ADMIN' AND status = 'ACTIVE'",
            "admin wallet": "SELECT COUNT(*) FROM wallets w JOIN users u ON u.id = w.user_id",
            "super-admin grants": "SELECT COUNT(*) FROM role_permissions rp "
                                  "JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id "
                                  "WHERE r.name = 'SUPER_ADMIN'",
            "vtu products": "SELECT COUNT(*) FROM vtu_products p JOIN vtu_networks n ON n.id = p.network_id",
        }
        for label, sql in checks.items():
            count = con.execute(sql).fetchone()[0]
            if count == 0:
                problems.append(f"after replay, {label} query returned no rows")
            else:
                notes.append(f"replay: {label} = {count}")
    notes.append(f"{executed} INSERTs replayed into SQLite")
    con.close()
    return problems


if __name__ == "__main__":
    sys.exit(main())
