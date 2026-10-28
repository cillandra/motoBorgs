import test from 'node:test';
import assert from 'node:assert/strict';
import { formatCurrency } from '../../resources/js/money.js';

test('live payment and return amounts use grouped pesos with exact centavos', () => {
    for (const [amount, expected] of [[0, '₱0.00'], [99999, '₱999.99'], [100001, '₱1,000.01'], [1807800, '₱18,078.00'], [-1807800, '₱-18,078.00'], [999999999, '₱9,999,999.99']]) {
        assert.equal(formatCurrency(amount), expected);
    }
});