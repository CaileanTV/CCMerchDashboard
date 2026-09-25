/**
 * Jest setup: tell React that updates are wrapped in act().
 */
(
	globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
 ).IS_REACT_ACT_ENVIRONMENT = true;
