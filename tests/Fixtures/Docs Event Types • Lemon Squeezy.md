| Event Name | Data Sent | Description |
|------------------------------|-----------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `order_created` | [Order object](https://docs.lemonsqueezy.com/api/orders#the-order-object) | Occurs when a new order is successfully placed. |
| `order_refunded` | [Order object](https://docs.lemonsqueezy.com/api/orders#the-order-object) | Occurs when a full or partial refund is made on an order. |
| `customer_updated` | [Customer object](https://docs.lemonsqueezy.com/api/customers#the-customer-object) | Occurs when a customer’s data is changed or updated. |
| `subscription_created` | [Subscription object](https://docs.lemonsqueezy.com/api/subscriptions#the-subscription-object) | Occurs when a new subscription is successfully created. An `order_created` event will always be sent alongside a `subscription_created` event. |
| `subscription_updated` | [Subscription object](https://docs.lemonsqueezy.com/api/subscriptions#the-subscription-object) | Occurs when a subscription’s data is changed or updated. This event can be used as a “catch-all” to make sure you always have access to the latest subscription data. |
| `subscription_cancelled` | [Subscription object](https://docs.lemonsqueezy.com/api/subscriptions#the-subscription-object) | Occurs when a subscription is cancelled manually by the customer or store owner. The subscription enters a “grace period” until the next billing date, when it will expire. It is possible for the subscription to be resumed during this period. |
| `subscription_resumed` | [Subscription object](https://docs.lemonsqueezy.com/api/subscriptions#the-subscription-object) | Occurs when a subscription is resumed after being previously cancelled. |
| `subscription_expired` | [Subscription object](https://docs.lemonsqueezy.com/api/subscriptions#the-subscription-object) | Occurs when a subscription has ended after being previously cancelled, or once dunning has been completed for `past_due` subscriptions. You can manage how long to wait before the system marks delinquent subscriptions as expired. [More info](https://docs.lemonsqueezy.com/help/online-store/recovery-dunning) |
| `subscription_paused` | [Subscription object](https://docs.lemonsqueezy.com/api/subscriptions#the-subscription-object) | Occurs when a subscription’s payment collection is paused. |
| `subscription_unpaused` | [Subscription object](https://docs.lemonsqueezy.com/api/subscriptions#the-subscription-object) | Occurs when a subscription’s payment collection is resumed after being previously paused. |
| `subscription_payment_success` | [Subscription invoice object](https://docs.lemonsqueezy.com/api/subscription-invoices#the-subscription-invoice-object) | Occurs when a subscription payment is successful. |
| `subscription_payment_failed` | [Subscription invoice object](https://docs.lemonsqueezy.com/api/subscription-invoices#the-subscription-invoice-object) | Occurs when a subscription renewal payment fails. |
| `subscription_payment_recovered` | [Subscription invoice object](https://docs.lemonsqueezy.com/api/subscription-invoices#the-subscription-invoice-object) | Occurs when a subscription has a successful payment after a failed payment. A `subscription_payment_success` event will always be sent alongside a `subscription_payment_recovered` event. |
| `subscription_payment_refunded` | [Subscription invoice object](https://docs.lemonsqueezy.com/api/subscription-invoices#the-subscription-invoice-object) | Occurs when a subscription payment is refunded. |
| `license_key_created` | [License key object](https://docs.lemonsqueezy.com/api/license-keys#the-license-key-object) | Occurs when a license key is created from a new order. An `order_created` event will always be sent alongside a `license_key_created` event. |
| `license_key_updated` | [License key object](https://docs.lemonsqueezy.com/api/license-keys#the-license-key-object) | Occurs when a license key is updated. |
| `affiliate_activated` | [Affiliate object](https://docs.lemonsqueezy.com/api/affiliates/the-affiliate-object) | Occurs when an affiliate is activated. |

## Recommended minimum webhooks[](https://docs.lemonsqueezy.com/help/webhooks/event-types#recommended-minimum-webhooks)

We suggest subscribing to these events as a minimum so that your application will have the latest data.

### Single payment[](https://docs.lemonsqueezy.com/help/webhooks/event-types#single-payment)

`order_created` - This event lets you know when new orders are placed.

### Subscriptions[](https://docs.lemonsqueezy.com/help/webhooks/event-types#subscriptions)

`subscription_created` - This event lets you know when new subscriptions are created.

`subscription_payment_success` - This event lets you save billing histories for your subscribing customers.

`subscription_updated` - This event keeps your application up-to-date with all changes to a subscription.

### Events sent during a subscription’s lifecycle[](https://docs.lemonsqueezy.com/help/webhooks/event-types#events-sent-during-a-subscriptions-lifecycle)

Different events will be triggered at various events during a subscription’s life.

You can see that `subscription_updated` is fired at every event after the initial payment. In most cases, subscribing to more granluar events like `subscription_resumed` or `subscription_expired` events as well as `subscription_updated` is not necessary.

#### Example 1: A typical flow of events[](https://docs.lemonsqueezy.com/help/webhooks/event-types#example-1-a-typical-flow-of-events)

Initial order is placed:

*   `order_created`
*   `subscription_created`
*   `subscription_payment_success`
*   `license_key_created` (if licenses are enabled on the subscription product)

Successful renewal payment:

*   `subscription_payment_success`
*   `subscription_updated`

Payment method updated by customer:

*   `subscription_updated`

Subscription manually cancelled by the customer or store owner:

*   `subscription_cancelled`
*   `subscription_updated`

Subscription expires when the cancelled subscription reaches the `ends_at` date:

*   `subscription_expired`
*   `subscription_updated`

#### Example 2: With payment failures[](https://docs.lemonsqueezy.com/help/webhooks/event-types#example-2-with-payment-failures)

Initial order is placed:

*   `order_created`
*   `subscription_created`
*   `subscription_payment_success`
*   `license_key_created` (if licenses are enabled on the subscription product)

Successful renewal payment:

*   `subscription_payment_success`
*   `subscription_updated`

Failed renewal payment (attempt 1):

*   `subscription_payment_failed`
*   `subscription_updated`

Successful renewal payment:

*   `subscription_payment_success`
*   `subscription_payment_recovered`
*   `subscription_updated`

Failed renewal payment (attempt 1):

*   `subscription_payment_failed`
*   `subscription_updated`

Failed renewal payment (attempt 2):

*   `subscription_payment_failed`
*   `subscription_updated`

Failed renewal payment (attempt 3):

*   `subscription_payment_failed`
*   `subscription_updated`

Failed renewal payment (attempt 4):

*   `subscription_payment_failed`
*   `subscription_updated`

(Subscription enters [dunning](https://docs.lemonsqueezy.com/help/online-store/recovery-dunning#dunning))

Dunning finishes:

*   `subscription_expired`
*   `subscription_updated`