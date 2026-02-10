import { describe, it, expect } from 'vitest';
import {
	formatNumber,
	formatBytes,
	formatDuration,
	formatRelativeTime,
	truncate,
	isValidDomain,
	isValidIP,
	cn
} from '$lib/utils';

describe('formatNumber', () => {
	it('formats numbers with locale', () => {
		expect(formatNumber(1000)).toBe('1,000');
		expect(formatNumber(1234567)).toBe('1,234,567');
	});

	it('handles zero', () => {
		expect(formatNumber(0)).toBe('0');
	});
});

describe('formatBytes', () => {
	it('formats bytes correctly', () => {
		expect(formatBytes(0)).toBe('0 Bytes');
		expect(formatBytes(1024)).toBe('1 KB');
		expect(formatBytes(1024 * 1024)).toBe('1 MB');
		expect(formatBytes(1024 * 1024 * 1024)).toBe('1 GB');
	});

	it('respects decimal places', () => {
		expect(formatBytes(1536, 1)).toBe('1.5 KB');
		expect(formatBytes(1536, 0)).toBe('2 KB');
	});
});

describe('formatDuration', () => {
	it('formats milliseconds', () => {
		expect(formatDuration(500)).toBe('500ms');
	});

	it('formats seconds', () => {
		expect(formatDuration(1500)).toBe('1.5s');
	});

	it('formats minutes', () => {
		expect(formatDuration(90000)).toBe('1m 30s');
	});

	it('formats hours', () => {
		expect(formatDuration(3700000)).toBe('1h 1m');
	});
});

describe('formatRelativeTime', () => {
	it('formats recent times', () => {
		const now = new Date();
		expect(formatRelativeTime(now)).toBe('just now');
	});

	it('formats minutes ago', () => {
		const fiveMinAgo = new Date(Date.now() - 5 * 60 * 1000);
		expect(formatRelativeTime(fiveMinAgo)).toBe('5m ago');
	});

	it('formats hours ago', () => {
		const threeHoursAgo = new Date(Date.now() - 3 * 60 * 60 * 1000);
		expect(formatRelativeTime(threeHoursAgo)).toBe('3h ago');
	});

	it('formats days ago', () => {
		const twoDaysAgo = new Date(Date.now() - 2 * 24 * 60 * 60 * 1000);
		expect(formatRelativeTime(twoDaysAgo)).toBe('2d ago');
	});
});

describe('truncate', () => {
	it('truncates long text', () => {
		expect(truncate('Hello World', 8)).toBe('Hello...');
	});

	it('does not truncate short text', () => {
		expect(truncate('Hello', 10)).toBe('Hello');
	});
});

describe('isValidDomain', () => {
	it('validates correct domains', () => {
		expect(isValidDomain('example.com')).toBe(true);
		expect(isValidDomain('sub.example.com')).toBe(true);
		expect(isValidDomain('my-site.co.uk')).toBe(true);
	});

	it('rejects invalid domains', () => {
		expect(isValidDomain('not a domain')).toBe(false);
		expect(isValidDomain('http://example.com')).toBe(false);
		expect(isValidDomain('.com')).toBe(false);
	});
});

describe('isValidIP', () => {
	it('validates IPv4 addresses', () => {
		expect(isValidIP('192.168.1.1')).toBe(true);
		expect(isValidIP('10.0.0.1')).toBe(true);
		expect(isValidIP('255.255.255.255')).toBe(true);
	});

	it('rejects invalid IPv4 addresses', () => {
		expect(isValidIP('256.1.1.1')).toBe(false);
		expect(isValidIP('192.168.1')).toBe(false);
		expect(isValidIP('not an ip')).toBe(false);
	});
});

describe('cn (class name utility)', () => {
	it('merges class names', () => {
		expect(cn('foo', 'bar')).toBe('foo bar');
	});

	it('handles conditional classes', () => {
		expect(cn('foo', false && 'bar', 'baz')).toBe('foo baz');
	});

	it('merges Tailwind classes correctly', () => {
		expect(cn('p-4', 'p-2')).toBe('p-2');
		expect(cn('text-red-500', 'text-blue-500')).toBe('text-blue-500');
	});
});
