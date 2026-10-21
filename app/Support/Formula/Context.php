<?php

namespace App\Support\Formula;

/** What a formula can see: header values, the current table row, whole columns, lookups. */
interface Context
{
    /** Value of a field (row column first when evaluating a line, then header, then _meta). */
    public function value(string $name);

    /** All values of "table.column" (or row count source for "table"). */
    public function column(string $ref): array;

    /** Rows of the current table up to and including the current row (for CUMSUM / PREV). */
    public function currentTable(): ?array;

    public function rowIndex(): ?int;

    /** Attribute of the record selected in a lookup field. */
    public function lookup(string $field, string $attr);

    /** Attribute of a master-data record found by another attribute. */
    public function find(string $source, string $attr, $value, string $return);
}
