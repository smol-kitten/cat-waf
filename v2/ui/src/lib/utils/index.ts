import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

/**
 * Utility function to merge Tailwind CSS classes
 */
export function cn(...inputs: ClassValue[]): string {
	return twMerge(clsx(inputs));
}

/**
 * Format number with locale
 */
export function formatNumber(value: number, options?: Intl.NumberFormatOptions): string {
	return new Intl.NumberFormat('en-US', options).format(value);
}

/**
 * Format bytes to human readable
 */
export function formatBytes(bytes: number, decimals = 2): string {
	if (bytes === 0) return '0 Bytes';

	const k = 1024;
	const dm = decimals < 0 ? 0 : decimals;
	const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];

	const i = Math.floor(Math.log(bytes) / Math.log(k));

	return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
}

/**
 * Format duration in milliseconds to human readable
 */
export function formatDuration(ms: number): string {
	if (ms < 1000) return `${ms}ms`;
	if (ms < 60000) return `${(ms / 1000).toFixed(1)}s`;
	if (ms < 3600000) return `${Math.floor(ms / 60000)}m ${Math.floor((ms % 60000) / 1000)}s`;
	return `${Math.floor(ms / 3600000)}h ${Math.floor((ms % 3600000) / 60000)}m`;
}

/**
 * Format date to relative time
 */
export function formatRelativeTime(date: Date | string): string {
	const now = new Date();
	const then = new Date(date);
	const diffMs = now.getTime() - then.getTime();
	const diffSec = Math.floor(diffMs / 1000);
	const diffMin = Math.floor(diffSec / 60);
	const diffHour = Math.floor(diffMin / 60);
	const diffDay = Math.floor(diffHour / 24);

	if (diffSec < 60) return 'just now';
	if (diffMin < 60) return `${diffMin}m ago`;
	if (diffHour < 24) return `${diffHour}h ago`;
	if (diffDay < 7) return `${diffDay}d ago`;

	return then.toLocaleDateString('en-US', {
		month: 'short',
		day: 'numeric',
		year: then.getFullYear() !== now.getFullYear() ? 'numeric' : undefined
	});
}

/**
 * Format date to ISO string (for inputs)
 */
export function formatDateISO(date: Date | string): string {
	const d = new Date(date);
	return d.toISOString().split('T')[0];
}

/**
 * Truncate text with ellipsis
 */
export function truncate(text: string, maxLength: number): string {
	if (text.length <= maxLength) return text;
	return text.slice(0, maxLength - 3) + '...';
}

/**
 * Debounce function
 */
export function debounce<T extends (...args: unknown[]) => unknown>(
	func: T,
	wait: number
): (...args: Parameters<T>) => void {
	let timeout: ReturnType<typeof setTimeout>;
	return function (this: unknown, ...args: Parameters<T>) {
		clearTimeout(timeout);
		timeout = setTimeout(() => func.apply(this, args), wait);
	};
}

/**
 * Copy text to clipboard
 */
export async function copyToClipboard(text: string): Promise<boolean> {
	try {
		await navigator.clipboard.writeText(text);
		return true;
	} catch {
		return false;
	}
}

/**
 * Check if string is valid URL
 */
export function isValidUrl(url: string): boolean {
	try {
		new URL(url);
		return true;
	} catch {
		return false;
	}
}

/**
 * Check if string is valid domain
 */
export function isValidDomain(domain: string): boolean {
	const pattern = /^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/;
	return pattern.test(domain);
}

/**
 * Check if string is valid IP address
 */
export function isValidIP(ip: string): boolean {
	const ipv4Pattern = /^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/;
	const ipv6Pattern = /^(?:[a-fA-F0-9]{1,4}:){7}[a-fA-F0-9]{1,4}$/;
	return ipv4Pattern.test(ip) || ipv6Pattern.test(ip);
}

/**
 * Severity level colors
 */
export const severityColors: Record<string, string> = {
	critical: 'bg-red-500/10 text-red-500 border-red-500/20',
	high: 'bg-orange-500/10 text-orange-500 border-orange-500/20',
	medium: 'bg-yellow-500/10 text-yellow-500 border-yellow-500/20',
	low: 'bg-blue-500/10 text-blue-500 border-blue-500/20',
	info: 'bg-gray-500/10 text-gray-500 border-gray-500/20'
};

/**
 * Status colors
 */
export const statusColors: Record<string, string> = {
	success: 'bg-green-500/10 text-green-500',
	warning: 'bg-yellow-500/10 text-yellow-500',
	error: 'bg-red-500/10 text-red-500',
	info: 'bg-blue-500/10 text-blue-500',
	pending: 'bg-gray-500/10 text-gray-500'
};
