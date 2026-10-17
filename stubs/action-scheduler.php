<?php
/**
 * Symbols for the bundled Action Scheduler build.
 *
 * @package SEOStudio
 */

/**
 * @param string               $hook
 * @param array<string, mixed> $args
 * @param string               $group
 */
function as_enqueue_async_action( $hook, $args = array(), $group = '' ): int {
	unset( $hook, $args, $group );
	return 0;
}

/**
 * @param int                  $timestamp
 * @param string               $hook
 * @param array<string, mixed> $args
 * @param string               $group
 */
function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '' ): int {
	unset( $timestamp, $hook, $args, $group );
	return 0;
}

/**
 * @param int                  $timestamp
 * @param int                  $interval
 * @param string               $hook
 * @param array<string, mixed> $args
 * @param string               $group
 */
function as_schedule_recurring_action( $timestamp, $interval, $hook, $args = array(), $group = '' ): int {
	unset( $timestamp, $interval, $hook, $args, $group );
	return 0;
}

/**
 * @param string               $hook
 * @param array<string, mixed> $args
 * @param string               $group
 */
function as_unschedule_all_actions( $hook, $args = array(), $group = '' ): void {
	unset( $hook, $args, $group );
}

/**
 * @param string               $hook
 * @param array<string, mixed> $args
 * @param string               $group
 */
function as_has_scheduled_action( $hook, $args = array(), $group = '' ): bool {
	unset( $hook, $args, $group );
	return false;
}
