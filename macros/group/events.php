<?php
/**
 * @package    hubzero-cms
 * @copyright  Copyright (c) 2005-2020 The Regents of the University of California.
 * @license    http://opensource.org/licenses/MIT MIT
 */

namespace Plugins\Content\Formathtml\Macros\Group;

require_once dirname(__DIR__) . DS . 'group.php';
require_once PATH_APP . DS . 'components' . DS . 'com_events' . DS . 'models' . DS . 'calendar.php';

use Plugins\Content\Formathtml\Macros\GroupMacro;
use Components\Events\Models\Calendar;

/**
 * Group events Macro
 */
class Events extends GroupMacro
{
	/**
	 * Allow macro in partial parsing?
	 *
	 * @var string
	 */
	public $allowPartial = true;

	/**
	 * Returns description of macro, use, and accepted arguments
	 *
	 * @return  array
	 */
	public function description()
	{
		$txt = array();
		$txt['html']  = '<p>Displays group events. All dates are in YYYY-MM-DD format.</p>';
		$txt['html'] .= '<p>Examples:</p>
							<ul>
								<li><code>[[Group.Events()]]</code> - Displays all future events</li>
								<li><code>[[Group.Events(from=2021-04-15)]]</code> - Displays events from 2021-04-15</li>
								<li><code>[[Group.Events(from=2021-04-15, for=3 months)]]</code> - Displays 3 months of events from 2021-04-15 (can use days, weeks, months, years)</li>
								<li><code>[[Group.Events(from=today, to=2025-01-01)]]</code> - Displays events from today to 2025-01-01</li>
								<li><code>[[Group.Events(to=today)]]</code> - Displays events up to today (not inclusive)</li>
							</ul>';

		return $txt['html'];
	}

	/**
	 * Generate macro output
	 *
	 * @return  string
	 */
	public function render()
	{
		// Check if we can render
		if (!parent::canRender())
		{
			return \Lang::txt('[This macro is designed for Groups only]');
		}

		// Get args
		$args = $this->getArgs();

		// Array of filters
		$filters = array();
		echo var_dump($args);

		// Get group events
		$events =  $this->getGroupEvents($this->group, $filters);

		// Create the html container
		$html  = '<div class="upcoming_events">';

		// Render the events
		$html .= $this->renderEvents($this->group, $events);

		// Close the container
		$html .= '</div>';

		// Return rendered events
		return $html;
	}

	/**
	 * Get a list of events for a group
	 *
	 * @param   object  $group
	 * @param   array   $filters
	 * @return  array
	 */
	private function getGroupEvents($group, $filters = array())
	{
		// Pulled from core/plugins/groups/calendar/calendar.php::events()

		// array to hold events
		$events = array();

		// get request params
		$start      = isset($filters['start']) ? $filters['start'] : '';
		$end        = isset($filters['end']) ? $filters['end'] : '';
		$calendarId = isset($filters['calendar_id']) ? $filters['calendar_id'] : 0;

		if ($start && !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $start))
		{
			$start = '';
		}
		if ($end && !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $end))
		{
			$end = '';
		}

		// format date/times
		$start = $start . ' 00:00:00';
		$end   = '0000-00-00 00:00:00';
		$until = Date::of('now')->add('1 year')->format('Y-m-d H:i:s');

		// get calendar events
		$eventsCalendar = \Components\Events\Models\Calendar::getInstance();
		$rawEvents = $eventsCalendar->events('list', array(
			'scope'        => 'group',
			'scope_id'     => $this->group->get('gidNumber'),
			'calendar_id'  => $calendarId,
			'state'        => array(1),
			'publish_up'   => $start, // $start->format('Y-m-d H:i:s'),
			'publish_down' => $end, // $end->format('Y-m-d H:i:s'),
			'non_repeating'    => true
		));

		// get repeating events
		$rawEventsRepeating = $eventsCalendar->events('repeating', array(
			'scope'        => 'group',
			'scope_id'     => $this->group->get('gidNumber'),
			'calendar_id'  => $calendarId,
			'state'        => array(1),
			'publish_up'   => $start, // $start->format('Y-m-d H:i:s'),
			'publish_down' => $end, // $end->format('Y-m-d H:i:s')
			'until'		   => $until // $end->format('Y-m-d H:i:s')
		));

		// merge events with repeating events
		$rawEvents = $rawEvents->merge($rawEventsRepeating);

		// loop through each event to return it
		foreach ($rawEvents as $rawEvent)
		{
			$up   = Date::of($rawEvent->get('publish_up'));
			$down = Date::of($rawEvent->get('publish_down'));
			$params = new \Hubzero\Config\Registry($rawEvent->get('params'));
			$ignoreDst = false;
			$ignoreDst = $params->get('ignore_dst') == 1 ? true : false;

			$event            = new \stdClass;
			$event->title     = $rawEvent->get('title');

			// Create URL to event
			$event->url       = $rawEvent->link();
			// add start & end for displaying dates user clicked on
			// instead of actual event start & end
			if ($rawEvent->get('repeating_rule') != '')
			{
				$event->url .= '?start=' . $up->toUnix();
				if ($rawEvent->get('publish_down') && $rawEvent->get('publish_down') != '0000-00-00 00:00:00')
				{
					$event->url .= '&end=' . $down->toUnix();
				}
			}
			$event->location = $rawEvent->get('adresse_info');
			$event->about = nl2br($rawEvent->get('content'));
			$strcap = 255;
			if (strlen($event->about) > $strcap)
			{
				$event->about = \Hubzero\Utility\Str::truncate($event->about, $strcap, array('html' => true)) . ' <a href="' . $event->url . '">[more]</a>';
			}

			$event->allDay    = $rawEvent->get('allday') == 1;
			$event->className = ($rawEvent->get('calendar_id')) ? 'calendar-' . $rawEvent->get('calendar_id') : 'calendar-0';
			$event->start_month = $up->format('M');
			$event->start_day = $up->format('d');
			$event->iso_8601 = $up->format('c');
			$event->year = $up->format('Y');
			$event->up = $up; // Used for sorting below
			if (!$event->allDay) {
				// Is this an open-ended event?
				if ($down <= Date::of('0000-00-00 00:00:00')) {
					// Open-ended event
					$event->start = $up->toLocal('g:i A T', $ignoreDst);
					$event->end = "(heat death of the universe)";
				} else {
					if ($up->format('Y-m-d') == $down->format('Y-m-d')) {
						// Single-day event
						$event->start = $up->toLocal('g:i A', $ignoreDst);
						$event->end = $down->toLocal('g:i A T', $ignoreDst);
					} else {
						// Multi-day event
						$event->start = $up->toLocal('M j g:i A T', $ignoreDst);
						$event->end = $down->toLocal('M j g:i A T', $ignoreDst);
					}
				}
			} else {
				// Adjustment for same-day all-day event
				if (Date::of($down)->subtract('24 hours') <= $up)
				{
					$down = $up->add('24 hours');
				}

				// Is this an open-ended event?
				if ($down <= Date::of('0000-00-00 00:00:00') || 
				    $down->format('Y-m-d') != $up->format('Y-m-d')) {
					$event->start = $up->format('M j');
					$event->end = $down->format('M j');
				} else {
					$event->start = 'All day';
					$event->end = '';
				}
			}

			array_push($events, $event);
		}

		// Put in order of start datetime
		uasort($events, function($a, $b) {
			return $a->up->toUnix() - $b->up->toUnix();
		});

		return $events;
	}

	/**
	 * Render the events
	 *
	 * @param  array  $group   Array of groups
	 * @param  array  $events  Array of group events
	 * @return string
	 */
	private function renderEvents($group, $events)
	{
		\Document::addStyleSheet(rtrim(str_replace(PATH_ROOT, '', __DIR__)) . DS . '../macro-assets' . DS . 'events' . DS . 'events.css');

		$content = '';
		$curr_year = '';
		if (count($events) > 0)
		{
			foreach ($events as $event)
			{
				if ($curr_year == '') {
					$content .= '<div class="item-list">';
					$content .= '<h3 class="year">' . $event->year . '</h3>';
					$content .= '<ul class="list-unstyled">';
					$curr_year = $event->year;
				} elseif ($curr_year != $event->year) {
					$content .= '</ul>';
					$content .= '</div>';
					$content .= '<div class="item-list">';
					$content .= '<h3 class="year">' . $event->year . '</h3>';
					$content .= '<ul class="list-unstyled">';
					$curr_year = $event->year;
				}
				$content .= '<li class="mb-4">';
				$content .= '  <div>';
				$content .= '    <div class="event-card_wrap">';
				$content .= '      <div class="event-card_left">';
				$content .= '        <div>';
				$content .= '          <time class="event-date_stacked" datetime="' . $event->iso_8601 . '">';
				$content .= '            <div class="event-date_stacked_month">' . $event->start_month . '</div>';
				$content .= '            <div class="event-date_stacked_day">' . $event->start_day . '</div>';
				$content .= '          </time>';
				$content .= '        </div>';
				$content .= '      </div>';
				$content .= '      <div class="event-card_body">';
				$content .= '        <div><h5><a href="' . $event->url . '">' . $event->title . '</a></h5></div>';
				$content .= '        <div class="event-time event-time_teaser">';
				$content .= '          <span>' . $event->start . '</span>';
				if ($event->end)
				{
					$content .= '          <span>&nbsp;to&nbsp; </span><span>' . $event->end . '</span>';
				}
				$content .= '        </div>';
				if ($event->location) {
					$content .= '        <div class="event-location location">';
					if (preg_match_all('/(http|ftp|https):\/\/([\w-]+(?:(?:\.[\w_-]+)+))([\w.,@?()<>;^=%&:\/~+#-]*[\w@?(<^=%&\/~+#-])?/', $event->location)) {
						$content .= '		  <a href="' . $event->location . '" target="_blank">' . $event->location . '</a>';
					} else {
						$content .= '		  <span>' . $event->location . '</span>';
					}
					$content .= '        </div>';
				}
				$content .= '        <div>' . $event->about . '</div>';
				$content .= '      </div>';
				$content .= '    </div>';
				$content .= '  </div>';
				$content .= '</li>';
			}
			$content .= '</div>';
			$content .= '</ul>';
		}
		else
		{
			$content .= '<p>Currently there are no upcoming group events. Add an event by <a href="' . \Route::url('index.php?option=com_groups&cn=' . $group->get('cn') . '&active=calendar&action=add') . '">clicking here.</a></p>';
		}

		return $content;
	}
}
