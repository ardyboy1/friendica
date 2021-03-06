<?php
/**
 * @file src/Model/PushSubscriber.php
 */
namespace Friendica\Model;

use Friendica\Core\Worker;
use Friendica\Util\DateTimeFormat;
use Friendica\Database\DBM;
use dba;

require_once 'include/dba.php';

class PushSubscriber
{
	/**
	 * @brief Send subscription notifications for the given user
	 *
	 * @param integer $uid      User ID
	 * @param string  $priority Priority for push workers
	 */
	public static function publishFeed($uid, $default_priority = PRIORITY_HIGH)
	{
		$condition = ['push' => 0, 'uid' => $uid];
		dba::update('push_subscriber', ['push' => 1, 'next_try' => NULL_DATE], $condition);

		self::requeue($default_priority);
	}

	/**
	 * @brief start workers to transmit the feed data
	 *
	 * @param string $priority Priority for push workers
	 */
	public static function requeue($default_priority = PRIORITY_HIGH)
	{
		// We'll push to each subscriber that has push > 0,
		// i.e. there has been an update (set in notifier.php).
		$subscribers = dba::select('push_subscriber', ['id', 'push', 'callback_url', 'nickname'], ["`push` > 0 AND `next_try` < UTC_TIMESTAMP()"]);

		while ($subscriber = dba::fetch($subscribers)) {
			// We always handle retries with low priority
			if ($subscriber['push'] > 1) {
				$priority = PRIORITY_LOW;
			} else {
				$priority = $default_priority;
			}

			logger('Publish feed to ' . $subscriber['callback_url'] . ' for ' . $subscriber['nickname'] . ' with priority ' . $priority, LOGGER_DEBUG);
			Worker::add($priority, 'PubSubPublish', (int)$subscriber['id']);
		}

		dba::close($subscribers);
	}

	/**
	 * @brief Renew the feed subscription
	 *
	 * @param integer $uid          User ID
	 * @param string  $nick         Priority for push workers
	 * @param integer $subscribe    Subscribe (Unsubscribe = false)
	 * @param string  $hub_callback Callback address
	 * @param string  $hub_topic    Feed topic
	 * @param string  $hub_secret   Subscription secret
	 */
	public static function renew($uid, $nick, $subscribe, $hub_callback, $hub_topic, $hub_secret)
	{
		// fetch the old subscription if it exists
		$subscriber = dba::selectFirst('push_subscriber', ['last_update', 'push'], ['callback_url' => $hub_callback]);

		// delete old subscription if it exists
		dba::delete('push_subscriber', ['callback_url' => $hub_callback]);

		if ($subscribe) {
			// if we are just updating an old subscription, keep the
			// old values for last_update but reset the push
			if (DBM::is_result($subscriber)) {
				$last_update = $subscriber['last_update'];
				$push_flag = min($subscriber['push'], 1);
			} else {
				$last_update = DateTimeFormat::utcNow();
				$push_flag = 0;
			}

			// subscribe means adding the row to the table
			$fields = ['uid' => $uid, 'callback_url' => $hub_callback,
				'topic' => $hub_topic, 'nickname' => $nick, 'push' => $push_flag,
				'last_update' => $last_update, 'renewed' => DateTimeFormat::utcNow(),
				'secret' => $hub_secret];
			dba::insert('push_subscriber', $fields);

			logger("Successfully subscribed [$hub_callback] for $nick");
		} else {
			logger("Successfully unsubscribed [$hub_callback] for $nick");
			// we do nothing here, since the row was already deleted
		}
	}

	/**
	 * @brief Delay the push subscriber
	 *
	 * @param integer $id Subscriber ID
	 */
	public static function delay($id)
	{
		$subscriber = dba::selectFirst('push_subscriber', ['push', 'callback_url', 'renewed', 'nickname'], ['id' => $id]);
		if (!DBM::is_result($subscriber)) {
			return;
		}

		$retrial = $subscriber['push'];

		if ($retrial > 14) {
			// End subscriptions if they weren't renewed for more than two months
			$days = round((time() -  strtotime($subscriber['renewed'])) / (60 * 60 * 24));

			if ($days > 60) {
				dba::update('push_subscriber', ['push' => -1, 'next_try' => NULL_DATE], ['id' => $id]);
				logger('Delivery error: Subscription ' . $subscriber['callback_url'] . ' for ' . $subscriber['nickname'] . ' is marked as ended.', LOGGER_DEBUG);
			} else {
				dba::update('push_subscriber', ['push' => 0, 'next_try' => NULL_DATE], ['id' => $id]);
				logger('Delivery error: Giving up ' . $subscriber['callback_url'] . ' for ' . $subscriber['nickname'] . ' for now.', LOGGER_DEBUG);
			}
		} else {
			// Calculate the delay until the next trial
			$delay = (($retrial + 3) ** 4) + (rand(1, 30) * ($retrial + 1));
			$next = DateTimeFormat::utc('now + ' . $delay . ' seconds');

			$retrial = $retrial + 1;

			dba::update('push_subscriber', ['push' => $retrial, 'next_try' => $next], ['id' => $id]);
			logger('Delivery error: Next try (' . $retrial . ') ' . $subscriber['callback_url'] . ' for ' . $subscriber['nickname'] . ' at ' . $next, LOGGER_DEBUG);
		}
	}

	/**
	 * @brief Reset the push subscriber
	 *
	 * @param integer $id          Subscriber ID
	 * @param date    $last_update Date of last transmitted item
	 */
	public static function reset($id, $last_update)
	{
		$subscriber = dba::selectFirst('push_subscriber', ['callback_url', 'nickname'], ['id' => $id]);
		if (!DBM::is_result($subscriber)) {
			return;
		}

		// set last_update to the 'created' date of the last item, and reset push=0
		$fields = ['push' => 0, 'next_try' => NULL_DATE, 'last_update' => $last_update];
		dba::update('push_subscriber', $fields, ['id' => $id]);
		logger('Subscriber ' . $subscriber['callback_url'] . ' for ' . $subscriber['nickname'] . ' is marked as vital', LOGGER_DEBUG);
	}
}
