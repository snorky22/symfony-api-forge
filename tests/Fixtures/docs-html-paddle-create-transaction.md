## Request body

statusstring

Status of this transaction. You may set a transaction to `billed` when creating, or omit to let Paddle set the status. Transactions are created as `ready` if they have an `address_id`, `customer_id`, and `items`, otherwise they are created as `draft`.

Marking as `billed` when creating is typically used when working with manually-collected transactions as part of an invoicing workflow. Billed transactions cannot be updated, only canceled.

Values

* `draft`

  Transaction is missing required fields. Typically the first stage of a checkout before customer details are captured.

* `ready`

  Transaction has all of the required fields to be marked as `billed` or `completed`.

* `billed`

  Transaction has been updated to `billed`. Billed transactions get an invoice number and are considered a legal record. They cannot be updated, only canceled. Typically used as part of an invoice workflow.

* `paid`

  Transaction is fully paid, but has not yet been processed internally.

* `completed`

  Transaction is fully paid and processed.

+ Show all values

* `canceled`

  Transaction has been updated to `canceled`. If an invoice, it's no longer due.

* `past_due`

  Transaction is past due. Occurs for automatically-collected transactions when the related subscription is in dunning, and for manually-collected transactions when no payment has been received 24 hours after the due date.

customer_idstring | null

Example: `ctm_01grnn4zta5a1mf02jjze7y2ys`

Paddle ID of the customer that this transaction is for, prefixed with `ctm_`. If omitted, transaction status is `draft`.

Pattern: `^ctm_[a-z\d]{26}$`

address_idstring | null

Example: `add_01gm302t81w94gyjpjpqypkzkf`

Paddle ID of the address that this transaction is for, prefixed with `add_`. Requires `customer_id`. If omitted, transaction status is `draft`.

Pattern: `^add_[a-z\d]{26}$`

business_idstring | null

Example: `biz_01grrebrzaee2qj2fqqhmcyzaj`

Paddle ID of the business that this transaction is for, prefixed with `biz_`. Requires `customer_id`.

Pattern: `^biz_[a-z\d]{26}$`

custom_dataobject | null

Example: `{"customer_reference_id":"abcd1234"}`

Your own structured key-value data.

currency_codestring | null

Supported three-letter ISO 4217 currency code. Must be `USD`, `EUR`, or `GBP` if `collection_mode` is `manual`.

Values

* `USD`

  United States Dollar

* `EUR`

  Euro

* `GBP`

  Pound Sterling

* `JPY`

  Japanese Yen

* `AUD`

  Australian Dollar

+ Show all values

* `CAD`

  Canadian Dollar

* `CHF`

  Swiss Franc

* `HKD`

  Hong Kong Dollar

* `SGD`

  Singapore Dollar

* `SEK`

  Swedish Krona

* `ARS`

  Argentine Peso

* `BRL`

  Brazilian Real

* `CLP`

  Chilean Peso

* `CNY`

  Chinese Yuan

* `COP`

  Colombian Peso

* `CZK`

  Czech Koruna

* `DKK`

  Danish Krone

* `HUF`

  Hungarian Forint

* `ILS`

  Israeli Shekel

* `INR`

  Indian Rupee

* `KRW`

  South Korean Won

* `MXN`

  Mexican Peso

* `NOK`

  Norwegian Krone

* `NZD`

  New Zealand Dollar

* `PEN`

  Peruvian Sol

* `PLN`

  Polish Zloty

* `RUB`

  Russian Ruble

* `THB`

  Thai Baht

* `TRY`

  Turkish Lira

* `TWD`

  New Taiwan Dollar

* `UAH`

  Ukrainian Hryvnia

* `VND`

  Vietnamese Dong

* `ZAR`

  South African Rand

collection_modestring

Default: `automatic`

How payment is collected for this transaction. `automatic` for checkout, `manual` for invoices. If omitted, defaults to `automatic`.

Values

* `automatic`

  Payment is collected automatically using a checkout initially, then using a payment method on file.

* `manual`

  Payment is collected manually. Customers are sent an invoice with payment terms and can make a payment offline or using a checkout. Requires `billing_details`.

discount_idstring | null

Example: `dsc_01gv5kpg05xp104ek2fmgjwttf`

Paddle ID of the discount to apply to this transaction, prefixed with `dsc_`. Send one of `discount_id` or `discount`.

Pattern: `^dsc_[a-z\d]{26}$`

discountobject | null

Apply a non-catalog discount to a transaction. Send one of `discount_id` or `discount`.

descriptionstringrequired

Short description for this discount for your reference. Not shown to customers.

Length: 1–500

typestringrequired

Type of discount. Determines how this discount impacts the checkout or transaction total.

Values

* `flat`

  Discounts a checkout or transaction by a flat amount, for example -$100. Requires `currency_code`.

* `flat_per_seat`

  Discounts a checkout or transaction by a flat amount per unit, for example -$100 per user. Requires `currency_code`.

* `percentage`

  Discounts a checkout or transaction by a percentage of the total, for example -10%. Maximum 100%.

amountstringrequired

Amount to discount by. For `percentage` discounts, must be an amount between `0.01` and `100`. For `flat` and `flat_per_seat` discounts, amount in the lowest denomination for a currency.

recurboolean

Default: `false`

Whether this discount applies for multiple subscription billing periods (`true`) or not (`false`). If omitted, defaults to `false`.

maximum_recurring_intervalsinteger | null

Number of subscription billing periods that this discount recurs for. Requires `recur`. `null` if this discount recurs forever.

Subscription renewals, midcycle changes, and one-time charges billed to a subscription aren't considered a redemption. `times_used` is not incremented in these cases.

Min: 1

custom_dataobject | null

Example: `{"customer_reference_id":"abcd1234"}`

Your own structured key-value data.

restrict_toarray | null

Product or price IDs that this discount is for. When including a product ID, all prices for that product can be discounted. `null` if this discount applies to all products and prices.

Items: unique

billing_detailsobject | null

Details for invoicing. Required if `collection_mode` is `manual`.

enable_checkoutboolean

Default: `false`

Whether the related transaction may be paid using Paddle Checkout. If omitted when creating a transaction, defaults to `false`.

purchase_order_numberstring

Customer purchase order number. Appears on invoice documents.

Max length: 100

additional_informationstring | null

Notes or other information to include on this invoice. Appears on invoice documents.

Max length: 1500

payment_termsobjectrequired

How long a customer has to pay this invoice once issued. The invoice is due at the end of its payment terms, and is marked as `past_due` 24 hours after its due date if unpaid.

intervalstringrequired

Unit of time.

Values

*   `day`
*   `week`
*   `month`
*   `year`

frequencyintegerrequired

Amount of time.

Min: 1

billing_periodobject | null

Time period that this transaction is for. Set automatically by Paddle for subscription renewals to describe the period that charges are for.

starts_atstring (date-time)required

Example: `2024-10-12T07:20:50.52Z`

RFC 3339 datetime string of when this period starts.

ends_atstring (date-time)required

Example: `2024-10-12T07:20:50.52Z`

RFC 3339 datetime string of when this period ends.

itemsarrayrequired

List of items to charge for. You can charge for items that you've added to your catalog by passing the Paddle ID of an existing price entity, or you can charge for non-catalog items by passing a price object.

Non-catalog items can be for existing products, or you can pass a product object as part of your price to charge for a non-catalog product.

Items: 1–100

Catalog item

Add a catalog item to a transaction. In this case, the product and price that you're billing for exist in your product catalog in Paddle.

quantityintegerrequired

Quantity of this item on the transaction.

Min: 1

price_idstringrequired

Example: `pri_01gsz8z1q1n00f12qt82y31smh`

Paddle ID of an existing catalog price to add to this transaction, prefixed with `pri_`.

Pattern: `^pri_[a-z\d]{26}$`

Non-catalog price for an existing product

Add a non-catalog price for an existing product in your catalog to a transaction. In this case, the product you're billing for is a catalog product, but you charge a specific price for it.

quantityintegerrequired

Quantity of this item on the transaction.

Min: 1

priceobjectrequired

Price object for a non-catalog item to charge for. Include a `product_id` to relate this non-catalog price to an existing catalog price.

descriptionstringrequired

Internal description for this price, not shown to customers. Typically notes for your team.

Length: 2–500

namestring | null

Name of this price, shown to customers at checkout and on invoices. Typically describes how often the related product bills.

Length: 1–150

billing_cycleobject | null

How often this price should be charged. `null` if price is non-recurring (one-time).

intervalstringrequired

Unit of time.

Values

*   `day`
*   `week`
*   `month`
*   `year`

frequencyintegerrequired

Amount of time.

Min: 1

trial_periodobject | null

Trial period for the product related to this price. The billing cycle begins once the trial period is over. `null` for no trial period. Requires `billing_cycle`.

intervalstringrequired

Unit of time.

Values

*   `day`
*   `week`
*   `month`
*   `year`

frequencyintegerrequired

Amount of time.

Min: 1

requires_payment_methodboolean

Default: `true`

Whether this price requires a payment method (`true`) or not (`false`) when trialing. If `false`, customers can sign up for subscription without entering their payment details, often referred to as a "cardless trial."

unit_priceobject | null

Trial price. Customers are billed this amount for the duration of the trial period. Applies to all customers except those in countries with `unit_price_overrides`. If `null`, customers are not charged during the trial.

amountstringrequired

Amount in the lowest denomination for the currency, e.g. 10 USD = 1000 (cents). Although represented as a string, this value must be a valid integer.

currency_codestring

Supported three-letter ISO 4217 currency code.

Values

* `USD`

  United States Dollar

* `EUR`

  Euro

* `GBP`

  Pound Sterling

* `JPY`

  Japanese Yen

* `AUD`

  Australian Dollar

+ Show all values

* `CAD`

  Canadian Dollar

* `CHF`

  Swiss Franc

* `HKD`

  Hong Kong Dollar

* `SGD`

  Singapore Dollar

* `SEK`

  Swedish Krona

* `ARS`

  Argentine Peso

* `BRL`

  Brazilian Real

* `CLP`

  Chilean Peso

* `CNY`

  Chinese Yuan

* `COP`

  Colombian Peso

* `CZK`

  Czech Koruna

* `DKK`

  Danish Krone

* `HUF`

  Hungarian Forint

* `ILS`

  Israeli Shekel

* `INR`

  Indian Rupee

* `KRW`

  South Korean Won

* `MXN`

  Mexican Peso

* `NOK`

  Norwegian Krone

* `NZD`

  New Zealand Dollar

* `PEN`

  Peruvian Sol

* `PLN`

  Polish Zloty

* `RUB`

  Russian Ruble

* `THB`

  Thai Baht

* `TRY`

  Turkish Lira

* `TWD`

  New Taiwan Dollar

* `UAH`

  Ukrainian Hryvnia

* `VND`

  Vietnamese Dong

* `ZAR`

  South African Rand

unit_price_overridesarray

List of unit price overrides for trial pricing. Use to override base trial price with a custom trial price and currency for a country or group of countries.

Items: max 250

country_codesarrayrequired

Supported two-letter ISO 3166-1 alpha-2 country code. Customers located in the listed countries are charged the override price.

Items: min 1, unique

Country code (supported)

Two-letter code for a country that Paddle supports sales to. Typically an ISO 3166-1 alpha-2 code, though Paddle uses a small number of codes that ISO doesn't currently assign.

Values

* `AD`

  Andorra

* `AE`

  United Arab Emirates

* `AG`

  Antigua and Barbuda

* `AI`

  Anguilla

* `AL`

  Albania

+ Show all values

* `AM`

  Armenia

* `AO`

  Angola

* `AR`

  Argentina

* `AS`

  American Samoa

* `AT`

  Austria

* `AU`

  Australia

* `AW`

  Aruba

* `AX`

  Åland Islands

* `AZ`

  Azerbaijan

* `BA`

  Bosnia and Herzegovina

* `BB`

  Barbados

* `BD`

  Bangladesh

* `BE`

  Belgium

* `BF`

  Burkina Faso

* `BG`

  Bulgaria

* `BH`

  Bahrain

* `BI`

  Burundi

* `BJ`

  Benin

* `BL`

  Saint Barthélemy

* `BM`

  Bermuda

* `BN`

  Brunei

* `BO`

  Bolivia

* `BQ`

  Caribbean Netherlands (Bonaire, Sint Eustatius, and Saba)

* `BR`

  Brazil

* `BS`

  Bahamas

* `BT`

  Bhutan

* `BV`

  Bouvet Island

* `BW`

  Botswana

* `BZ`

  Belize

* `CA`

  Canada

* `CC`

  Cocos Islands

* `CG`

  Republic of Congo

* `CH`

  Switzerland

* `CI`

  Côte d'Ivoire (Ivory Coast)

* `CK`

  Cook Islands

* `CL`

  Chile

* `CM`

  Cameroon

* `CN`

  China

* `CO`

  Colombia

* `CR`

  Costa Rica

* `CV`

  Cape Verde

* `CW`

  Curaçao

* `CX`

  Christmas Island

* `CY`

  Cyprus

* `CZ`

  Czechia (Czech Republic)

* `DE`

  Germany

* `DJ`

  Djibouti

* `DK`

  Denmark

* `DM`

  Dominica

* `DO`

  Dominican Republic

* `DZ`

  Algeria

* `EC`

  Ecuador

* `EE`

  Estonia

* `EG`

  Egypt

* `EH`

  Western Sahara

* `ER`

  Eritrea

* `ES`

  Spain

* `ET`

  Ethiopia

* `FI`

  Finland

* `FJ`

  Fiji

* `FK`

  Falkland Islands

* `FM`

  Micronesia

* `FO`

  Faroe Islands

* `FR`

  France

* `GA`

  Gabon

* `GB`

  United Kingdom

* `GD`

  Grenada

* `GE`

  Georgia

* `GF`

  French Guiana

* `GG`

  Guernsey

* `GH`

  Ghana

* `GI`

  Gibraltar

* `GL`

  Greenland

* `GM`

  Gambia

* `GN`

  Guinea

* `GP`

  Guadeloupe

* `GQ`

  Equatorial Guinea

* `GR`

  Greece

* `GS`

  South Georgia and the South Sandwich Islands

* `GT`

  Guatemala

* `GU`

  Guam

* `GW`

  Guinea-Bissau

* `GY`

  Guyana

* `HK`

  Hong Kong

* `HM`

  Heard Island and McDonald Islands

* `HN`

  Honduras

* `HR`

  Croatia

* `HU`

  Hungary

* `ID`

  Indonesia

* `IE`

  Ireland

* `IL`

  Israel

* `IM`

  Isle of Man

* `IN`

  India

* `IO`

  British Indian Ocean Territory

* `IQ`

  Iraq

* `IS`

  Iceland

* `IT`

  Italy

* `JE`

  Jersey

* `JM`

  Jamaica

* `JO`

  Jordan

* `JP`

  Japan

* `KE`

  Kenya

* `KG`

  Kyrgyzstan

* `KH`

  Cambodia

* `KI`

  Kiribati

* `KM`

  Comoros

* `KN`

  Saint Kitts and Nevis

* `KR`

  South Korea

* `KW`

  Kuwait

* `KY`

  Cayman Islands

* `KZ`

  Kazakhstan

* `LA`

  Lao People's Democratic Republic (Laos)

* `LB`

  Lebanon

* `LC`

  Saint Lucia

* `LI`

  Liechtenstein

* `LK`

  Sri Lanka

* `LR`

  Liberia

* `LS`

  Lesotho

* `LT`

  Lithuania

* `LU`

  Luxembourg

* `LV`

  Latvia

* `MA`

  Morocco

* `MC`

  Monaco

* `MD`

  Moldova

* `ME`

  Montenegro

* `MF`

  Saint Martin

* `MG`

  Madagascar

* `MH`

  Marshall Islands

* `MK`

  Macedonia

* `MN`

  Mongolia

* `MO`

  Macao

* `MP`

  Northern Mariana Islands

* `MQ`

  Martinique

* `MR`

  Mauritania

* `MS`

  Montserrat

* `MT`

  Malta

* `MU`

  Mauritius

* `MV`

  Maldives

* `MW`

  Malawi

* `MX`

  Mexico

* `MY`

  Malaysia

* `MZ`

  Mozambique

* `NA`

  Namibia

* `NC`

  New Caledonia

* `NE`

  Niger

* `NF`

  Norfolk Island

* `NG`

  Nigeria

* `NL`

  Netherlands

* `NO`

  Norway

* `NP`

  Nepal

* `NR`

  Nauru

* `NU`

  Niue

* `NZ`

  New Zealand

* `OM`

  Oman

* `PA`

  Panama

* `PE`

  Peru

* `PF`

  French Polynesia

* `PG`

  Papua New Guinea

* `PH`

  Philippines

* `PK`

  Pakistan

* `PL`

  Poland

* `PM`

  Saint Pierre and Miquelon

* `PN`

  Pitcairn

* `PR`

  Puerto Rico

* `PS`

  Palestinian territories

* `PT`

  Portugal

* `PW`

  Palau

* `PY`

  Paraguay

* `QA`

  Qatar

* `RE`

  Reunion

* `RO`

  Romania

* `RS`

  Republic of Serbia

* `RW`

  Rwanda

* `SA`

  Saudi Arabia

* `SB`

  Solomon Islands

* `SC`

  Seychelles

* `SE`

  Sweden

* `SG`

  Singapore

* `SH`

  Saint Helena

* `SI`

  Slovenia

* `SJ`

  Svalbard and Jan Mayen

* `SK`

  Slovakia

* `SL`

  Sierra Leone

* `SM`

  San Marino

* `SN`

  Senegal

* `SR`

  Suriname

* `ST`

  São Tomé and Príncipe

* `SV`

  El Salvador

* `SX`

  Sint Maarten

* `SZ`

  Swaziland

* `TC`

  Turks and Caicos Islands

* `TD`

  Chad

* `TF`

  French Southern and Antarctic Lands

* `TG`

  Togo

* `TH`

  Thailand

* `TJ`

  Tajikistan

* `TK`

  Tokelau

* `TL`

  Timor-Leste

* `TM`

  Turkmenistan

* `TN`

  Tunisia

* `TO`

  Tonga

* `TR`

  Turkey

* `TT`

  Trinidad and Tobago

* `TV`

  Tuvalu

* `TW`

  Taiwan

* `TZ`

  Tanzania

* `UA`

  Ukraine

* `UG`

  Uganda

* `UM`

  United States Minor Outlying Islands

* `US`

  United States

* `UY`

  Uruguay

* `UZ`

  Uzbekistan

* `VA`

  Holy See (Vatican City)

* `VC`

  Saint Vincent and the Grenadines

* `VG`

  British Virgin Islands

* `VI`

  U.S. Virgin Islands

* `VN`

  Vietnam

* `VU`

  Vanuatu

* `WF`

  Wallis and Futuna

* `WS`

  Samoa

* `XK`

  Kosovo

* `YT`

  Mayotte

* `ZA`

  South Africa

* `ZM`

  Zambia

Country code (unsupported)

Two-letter code for a country that Paddle doesn't support sales to. Typically an ISO 3166-1 alpha-2 code, though Paddle uses a small number of codes that ISO doesn't currently assign.

Values

* `AF`

  Afghanistan

* `AQ`

  Antarctica

* `AN`

  Netherlands Antilles. Withdrawn ISO 3166-1 code, succeeded by BQ, CW and SX.

* `BY`

  Belarus

* `CD`

  Democratic Republic of Congo

+ Show all values

* `CF`

  Central African Republic

* `CU`

  Cuba

* `CS`

  Serbia and Montenegro. Withdrawn ISO 3166-1 code, succeeded by RS and ME.

* `HT`

  Haiti

* `IR`

  Iran

* `KP`

  North Korea

* `LY`

  Libya

* `ML`

  Mali

* `MM`

  Myanmar (Burma)

* `NI`

  Nicaragua

* `RU`

  Russia

* `SO`

  Somalia

* `SS`

  South Sudan

* `SD`

  Sudan

* `SY`

  Syria

* `VE`

  Venezuela

* `YE`

  Yemen

* `ZW`

  Zimbabwe

unit_priceobjectrequired

Override price. This price applies to customers located in the countries for this unit price override.

amountstringrequired

Amount in the lowest denomination for the currency, e.g. 10 USD = 1000 (cents). Although represented as a string, this value must be a valid integer.

currency_codestring

Supported three-letter ISO 4217 currency code.

Values

* `USD`

  United States Dollar

* `EUR`

  Euro

* `GBP`

  Pound Sterling

* `JPY`

  Japanese Yen

* `AUD`

  Australian Dollar

+ Show all values

* `CAD`

  Canadian Dollar

* `CHF`

  Swiss Franc

* `HKD`

  Hong Kong Dollar

* `SGD`

  Singapore Dollar

* `SEK`

  Swedish Krona

* `ARS`

  Argentine Peso

* `BRL`

  Brazilian Real

* `CLP`

  Chilean Peso

* `CNY`

  Chinese Yuan

* `COP`

  Colombian Peso

* `CZK`

  Czech Koruna

* `DKK`

  Danish Krone

* `HUF`

  Hungarian Forint

* `ILS`

  Israeli Shekel

* `INR`

  Indian Rupee

* `KRW`

  South Korean Won

* `MXN`

  Mexican Peso

* `NOK`

  Norwegian Krone

* `NZD`

  New Zealand Dollar

* `PEN`

  Peruvian Sol

* `PLN`

  Polish Zloty

* `RUB`

  Russian Ruble

* `THB`

  Thai Baht

* `TRY`

  Turkish Lira

* `TWD`

  New Taiwan Dollar

* `UAH`

  Ukrainian Hryvnia

* `VND`

  Vietnamese Dong

* `ZAR`

  South African Rand

tax_modestring

Default: `account_setting`

How tax is calculated for this price.

Values

* `account_setting`

  Prices use the setting from your account.

* `external`

  Prices are exclusive of tax.

* `internal`

  Prices are inclusive of tax.

* `location`

  Prices are inclusive or exclusive of tax, depending on the country of the transaction.

unit_priceobjectrequired

Base price. This price applies to all customers, except for customers located in countries where you have `unit_price_overrides`.

amountstringrequired

Amount in the lowest denomination for the currency, e.g. 10 USD = 1000 (cents). Although represented as a string, this value must be a valid integer.

currency_codestringrequired

Supported three-letter ISO 4217 currency code.

Values

* `USD`

  United States Dollar

* `EUR`

  Euro

* `GBP`

  Pound Sterling

* `JPY`

  Japanese Yen

* `AUD`

  Australian Dollar

+ Show all values

* `CAD`

  Canadian Dollar

* `CHF`

  Swiss Franc

* `HKD`

  Hong Kong Dollar

* `SGD`

  Singapore Dollar

* `SEK`

  Swedish Krona

* `ARS`

  Argentine Peso

* `BRL`

  Brazilian Real

* `CLP`

  Chilean Peso

* `CNY`

  Chinese Yuan

* `COP`

  Colombian Peso

* `CZK`

  Czech Koruna

* `DKK`

  Danish Krone

* `HUF`

  Hungarian Forint

* `ILS`

  Israeli Shekel

* `INR`

  Indian Rupee

* `KRW`

  South Korean Won

* `MXN`

  Mexican Peso

* `NOK`

  Norwegian Krone

* `NZD`

  New Zealand Dollar

* `PEN`

  Peruvian Sol

* `PLN`

  Polish Zloty

* `RUB`

  Russian Ruble

* `THB`

  Thai Baht

* `TRY`

  Turkish Lira

* `TWD`

  New Taiwan Dollar

* `UAH`

  Ukrainian Hryvnia

* `VND`

  Vietnamese Dong

* `ZAR`

  South African Rand

unit_price_overridesarray

List of unit price overrides. Use to override the base price with a custom price and currency for a country or group of countries.

Items: max 250

country_codesarrayrequired

Supported two-letter ISO 3166-1 alpha-2 country code. Customers located in the listed countries are charged the override price.

Items: min 1, unique

Country code (supported)

Two-letter code for a country that Paddle supports sales to. Typically an ISO 3166-1 alpha-2 code, though Paddle uses a small number of codes that ISO doesn't currently assign.

Values

* `AD`

  Andorra

* `AE`

  United Arab Emirates

* `AG`

  Antigua and Barbuda

* `AI`

  Anguilla

* `AL`

  Albania

+ Show all values

* `AM`

  Armenia

* `AO`

  Angola

* `AR`

  Argentina

* `AS`

  American Samoa

* `AT`

  Austria

* `AU`

  Australia

* `AW`

  Aruba

* `AX`

  Åland Islands

* `AZ`

  Azerbaijan

* `BA`

  Bosnia and Herzegovina

* `BB`

  Barbados

* `BD`

  Bangladesh

* `BE`

  Belgium

* `BF`

  Burkina Faso

* `BG`

  Bulgaria

* `BH`

  Bahrain

* `BI`

  Burundi

* `BJ`

  Benin

* `BL`

  Saint Barthélemy

* `BM`

  Bermuda

* `BN`

  Brunei

* `BO`

  Bolivia

* `BQ`

  Caribbean Netherlands (Bonaire, Sint Eustatius, and Saba)

* `BR`

  Brazil

* `BS`

  Bahamas

* `BT`

  Bhutan

* `BV`

  Bouvet Island

* `BW`

  Botswana

* `BZ`

  Belize

* `CA`

  Canada

* `CC`

  Cocos Islands

* `CG`

  Republic of Congo

* `CH`

  Switzerland

* `CI`

  Côte d'Ivoire (Ivory Coast)

* `CK`

  Cook Islands

* `CL`

  Chile

* `CM`

  Cameroon

* `CN`

  China

* `CO`

  Colombia

* `CR`

  Costa Rica

* `CV`

  Cape Verde

* `CW`

  Curaçao

* `CX`

  Christmas Island

* `CY`

  Cyprus

* `CZ`

  Czechia (Czech Republic)

* `DE`

  Germany

* `DJ`

  Djibouti

* `DK`

  Denmark

* `DM`

  Dominica

* `DO`

  Dominican Republic

* `DZ`

  Algeria

* `EC`

  Ecuador

* `EE`

  Estonia

* `EG`

  Egypt

* `EH`

  Western Sahara

* `ER`

  Eritrea

* `ES`

  Spain

* `ET`

  Ethiopia

* `FI`

  Finland

* `FJ`

  Fiji

* `FK`

  Falkland Islands

* `FM`

  Micronesia

* `FO`

  Faroe Islands

* `FR`

  France

* `GA`

  Gabon

* `GB`

  United Kingdom

* `GD`

  Grenada

* `GE`

  Georgia

* `GF`

  French Guiana

* `GG`

  Guernsey

* `GH`

  Ghana

* `GI`

  Gibraltar

* `GL`

  Greenland

* `GM`

  Gambia

* `GN`

  Guinea

* `GP`

  Guadeloupe

* `GQ`

  Equatorial Guinea

* `GR`

  Greece

* `GS`

  South Georgia and the South Sandwich Islands

* `GT`

  Guatemala

* `GU`

  Guam

* `GW`

  Guinea-Bissau

* `GY`

  Guyana

* `HK`

  Hong Kong

* `HM`

  Heard Island and McDonald Islands

* `HN`

  Honduras

* `HR`

  Croatia

* `HU`

  Hungary

* `ID`

  Indonesia

* `IE`

  Ireland

* `IL`

  Israel

* `IM`

  Isle of Man

* `IN`

  India

* `IO`

  British Indian Ocean Territory

* `IQ`

  Iraq

* `IS`

  Iceland

* `IT`

  Italy

* `JE`

  Jersey

* `JM`

  Jamaica

* `JO`

  Jordan

* `JP`

  Japan

* `KE`

  Kenya

* `KG`

  Kyrgyzstan

* `KH`

  Cambodia

* `KI`

  Kiribati

* `KM`

  Comoros

* `KN`

  Saint Kitts and Nevis

* `KR`

  South Korea

* `KW`

  Kuwait

* `KY`

  Cayman Islands

* `KZ`

  Kazakhstan

* `LA`

  Lao People's Democratic Republic (Laos)

* `LB`

  Lebanon

* `LC`

  Saint Lucia

* `LI`

  Liechtenstein

* `LK`

  Sri Lanka

* `LR`

  Liberia

* `LS`

  Lesotho

* `LT`

  Lithuania

* `LU`

  Luxembourg

* `LV`

  Latvia

* `MA`

  Morocco

* `MC`

  Monaco

* `MD`

  Moldova

* `ME`

  Montenegro

* `MF`

  Saint Martin

* `MG`

  Madagascar

* `MH`

  Marshall Islands

* `MK`

  Macedonia

* `MN`

  Mongolia

* `MO`

  Macao

* `MP`

  Northern Mariana Islands

* `MQ`

  Martinique

* `MR`

  Mauritania

* `MS`

  Montserrat

* `MT`

  Malta

* `MU`

  Mauritius

* `MV`

  Maldives

* `MW`

  Malawi

* `MX`

  Mexico

* `MY`

  Malaysia

* `MZ`

  Mozambique

* `NA`

  Namibia

* `NC`

  New Caledonia

* `NE`

  Niger

* `NF`

  Norfolk Island

* `NG`

  Nigeria

* `NL`

  Netherlands

* `NO`

  Norway

* `NP`

  Nepal

* `NR`

  Nauru

* `NU`

  Niue

* `NZ`

  New Zealand

* `OM`

  Oman

* `PA`

  Panama

* `PE`

  Peru

* `PF`

  French Polynesia

* `PG`

  Papua New Guinea

* `PH`

  Philippines

* `PK`

  Pakistan

* `PL`

  Poland

* `PM`

  Saint Pierre and Miquelon

* `PN`

  Pitcairn

* `PR`

  Puerto Rico

* `PS`

  Palestinian territories

* `PT`

  Portugal

* `PW`

  Palau

* `PY`

  Paraguay

* `QA`

  Qatar

* `RE`

  Reunion

* `RO`

  Romania

* `RS`

  Republic of Serbia

* `RW`

  Rwanda

* `SA`

  Saudi Arabia

* `SB`

  Solomon Islands

* `SC`

  Seychelles

* `SE`

  Sweden

* `SG`

  Singapore

* `SH`

  Saint Helena

* `SI`

  Slovenia

* `SJ`

  Svalbard and Jan Mayen

* `SK`

  Slovakia

* `SL`

  Sierra Leone

* `SM`

  San Marino

* `SN`

  Senegal

* `SR`

  Suriname

* `ST`

  São Tomé and Príncipe

* `SV`

  El Salvador

* `SX`

  Sint Maarten

* `SZ`

  Swaziland

* `TC`

  Turks and Caicos Islands

* `TD`

  Chad

* `TF`

  French Southern and Antarctic Lands

* `TG`

  Togo

* `TH`

  Thailand

* `TJ`

  Tajikistan

* `TK`

  Tokelau

* `TL`

  Timor-Leste

* `TM`

  Turkmenistan

* `TN`

  Tunisia

* `TO`

  Tonga

* `TR`

  Turkey

* `TT`

  Trinidad and Tobago

* `TV`

  Tuvalu

* `TW`

  Taiwan

* `TZ`

  Tanzania

* `UA`

  Ukraine

* `UG`

  Uganda

* `UM`

  United States Minor Outlying Islands

* `US`

  United States

* `UY`

  Uruguay

* `UZ`

  Uzbekistan

* `VA`

  Holy See (Vatican City)

* `VC`

  Saint Vincent and the Grenadines

* `VG`

  British Virgin Islands

* `VI`

  U.S. Virgin Islands

* `VN`

  Vietnam

* `VU`

  Vanuatu

* `WF`

  Wallis and Futuna

* `WS`

  Samoa

* `XK`

  Kosovo

* `YT`

  Mayotte

* `ZA`

  South Africa

* `ZM`

  Zambia

Country code (unsupported)

Two-letter code for a country that Paddle doesn't support sales to. Typically an ISO 3166-1 alpha-2 code, though Paddle uses a small number of codes that ISO doesn't currently assign.

Values

* `AF`

  Afghanistan

* `AQ`

  Antarctica

* `AN`

  Netherlands Antilles. Withdrawn ISO 3166-1 code, succeeded by BQ, CW and SX.

* `BY`

  Belarus

* `CD`

  Democratic Republic of Congo

+ Show all values

* `CF`

  Central African Republic

* `CU`

  Cuba

* `CS`

  Serbia and Montenegro. Withdrawn ISO 3166-1 code, succeeded by RS and ME.

* `HT`

  Haiti

* `IR`

  Iran

* `KP`

  North Korea

* `LY`

  Libya

* `ML`

  Mali

* `MM`

  Myanmar (Burma)

* `NI`

  Nicaragua

* `RU`

  Russia

* `SO`

  Somalia

* `SS`

  South Sudan

* `SD`

  Sudan

* `SY`

  Syria

* `VE`

  Venezuela

* `YE`

  Yemen

* `ZW`

  Zimbabwe

unit_priceobjectrequired

Override price. This price applies to customers located in the countries for this unit price override.

amountstringrequired

Amount in the lowest denomination for the currency, e.g. 10 USD = 1000 (cents). Although represented as a string, this value must be a valid integer.

currency_codestringrequired

Supported three-letter ISO 4217 currency code.

Values

* `USD`

  United States Dollar

* `EUR`

  Euro

* `GBP`

  Pound Sterling

* `JPY`

  Japanese Yen

* `AUD`

  Australian Dollar

+ Show all values

* `CAD`

  Canadian Dollar

* `CHF`

  Swiss Franc

* `HKD`

  Hong Kong Dollar

* `SGD`

  Singapore Dollar

* `SEK`

  Swedish Krona

* `ARS`

  Argentine Peso

* `BRL`

  Brazilian Real

* `CLP`

  Chilean Peso

* `CNY`

  Chinese Yuan

* `COP`

  Colombian Peso

* `CZK`

  Czech Koruna

* `DKK`

  Danish Krone

* `HUF`

  Hungarian Forint

* `ILS`

  Israeli Shekel

* `INR`

  Indian Rupee

* `KRW`

  South Korean Won

* `MXN`

  Mexican Peso

* `NOK`

  Norwegian Krone

* `NZD`

  New Zealand Dollar

* `PEN`

  Peruvian Sol

* `PLN`

  Polish Zloty

* `RUB`

  Russian Ruble

* `THB`

  Thai Baht

* `TRY`

  Turkish Lira

* `TWD`

  New Taiwan Dollar

* `UAH`

  Ukrainian Hryvnia

* `VND`

  Vietnamese Dong

* `ZAR`

  South African Rand

quantityobject

Example: `{"minimum":1,"maximum":100}`

Limits on how many times the related product can be purchased at this price. Useful for discount campaigns. If omitted, defaults to 1-100.

minimumintegerrequired

Default: `1`

Example: `1`

Minimum quantity of the product related to this price that can be bought. Required if `maximum` set.

Range: 1–999999999

maximumintegerrequired

Default: `100`

Example: `100`

Maximum quantity of the product related to this price that can be bought. Required if `minimum` set. Must be greater than or equal to the `minimum` value.

Range: 1–999999999

allowancesarrayearly access

Usage that this price grants for your usage balance units each billing period. Only allowed on recurring prices without a trial period. Transactions and subscriptions with these prices must be collected automatically, and can't use prorated billing modes when they change. At most one allowance for each usage balance unit, and at most 20 allowances in total. If omitted, this price doesn't grant any usage.

Items: max 20, unique

usage_balance_unit_idstringrequired

Example: `ubu_01kw8ta2j8x5t7q4v9m1kd6phz`

Paddle ID of the usage balance unit that this price grants.

Pattern: `^ubu_[a-z\d]{26}$`

amountstringrequired

Amount of the usage balance unit that this price grants each billing period. Paddle multiplies it by the item quantity. If `"0"`, customers get a usage balance with nothing in it, so all their usage is recorded as overage.

Max length: 45

Pattern: `^(0|[1-9][0-9]{0,34})(\.[0-9]{1,9})?$`

custom_dataobject | null

Example: `{"customer_reference_id":"abcd1234"}`

Your own structured key-value data.

product_idstringrequired

Example: `pro_01gsz97mq9pa4fkyy0wqenepkz`

Paddle ID for the product that this price is for, prefixed with `pro_`.

Pattern: `^pro_[a-z\d]{26}$`

Non-catalog price and product

Add a non-catalog price for a non-catalog product in your catalog to a transaction. In this case, the product and price that you're billing for are specific to this transaction.

quantityintegerrequired

Quantity of this item on the transaction.

Min: 1

priceobjectrequired

Price object for a non-catalog item to charge for. Include a `product` object to create a non-catalog product for this non-catalog price.

descriptionstringrequired

Internal description for this price, not shown to customers. Typically notes for your team.

Length: 2–500

namestring | null

Name of this price, shown to customers at checkout and on invoices. Typically describes how often the related product bills.

Length: 1–150

billing_cycleobject | null

How often this price should be charged. `null` if price is non-recurring (one-time).

intervalstringrequired

Unit of time.

Values

*   `day`
*   `week`
*   `month`
*   `year`

frequencyintegerrequired

Amount of time.

Min: 1

trial_periodobject | null

Trial period for the product related to this price. The billing cycle begins once the trial period is over. `null` for no trial period. Requires `billing_cycle`.

intervalstringrequired

Unit of time.

Values

*   `day`
*   `week`
*   `month`
*   `year`

frequencyintegerrequired

Amount of time.

Min: 1

requires_payment_methodboolean

Default: `true`

Whether this price requires a payment method (`true`) or not (`false`) when trialing. If `false`, customers can sign up for subscription without entering their payment details, often referred to as a "cardless trial."

unit_priceobject | null

Trial price. Customers are billed this amount for the duration of the trial period. Applies to all customers except those in countries with `unit_price_overrides`. If `null`, customers are not charged during the trial.

amountstringrequired

Amount in the lowest denomination for the currency, e.g. 10 USD = 1000 (cents). Although represented as a string, this value must be a valid integer.

currency_codestring

Supported three-letter ISO 4217 currency code.

Values

* `USD`

  United States Dollar

* `EUR`

  Euro

* `GBP`

  Pound Sterling

* `JPY`

  Japanese Yen

* `AUD`

  Australian Dollar

+ Show all values

* `CAD`

  Canadian Dollar

* `CHF`

  Swiss Franc

* `HKD`

  Hong Kong Dollar

* `SGD`

  Singapore Dollar

* `SEK`

  Swedish Krona

* `ARS`

  Argentine Peso

* `BRL`

  Brazilian Real

* `CLP`

  Chilean Peso

* `CNY`

  Chinese Yuan

* `COP`

  Colombian Peso

* `CZK`

  Czech Koruna

* `DKK`

  Danish Krone

* `HUF`

  Hungarian Forint

* `ILS`

  Israeli Shekel

* `INR`

  Indian Rupee

* `KRW`

  South Korean Won

* `MXN`

  Mexican Peso

* `NOK`

  Norwegian Krone

* `NZD`

  New Zealand Dollar

* `PEN`

  Peruvian Sol

* `PLN`

  Polish Zloty

* `RUB`

  Russian Ruble

* `THB`

  Thai Baht

* `TRY`

  Turkish Lira

* `TWD`

  New Taiwan Dollar

* `UAH`

  Ukrainian Hryvnia

* `VND`

  Vietnamese Dong

* `ZAR`

  South African Rand

unit_price_overridesarray

List of unit price overrides for trial pricing. Use to override base trial price with a custom trial price and currency for a country or group of countries.

Items: max 250

country_codesarrayrequired

Supported two-letter ISO 3166-1 alpha-2 country code. Customers located in the listed countries are charged the override price.

Items: min 1, unique

Country code (supported)

Two-letter code for a country that Paddle supports sales to. Typically an ISO 3166-1 alpha-2 code, though Paddle uses a small number of codes that ISO doesn't currently assign.

Values

* `AD`

  Andorra

* `AE`

  United Arab Emirates

* `AG`

  Antigua and Barbuda

* `AI`

  Anguilla

* `AL`

  Albania

+ Show all values

* `AM`

  Armenia

* `AO`

  Angola

* `AR`

  Argentina

* `AS`

  American Samoa

* `AT`

  Austria

* `AU`

  Australia

* `AW`

  Aruba

* `AX`

  Åland Islands

* `AZ`

  Azerbaijan

* `BA`

  Bosnia and Herzegovina

* `BB`

  Barbados

* `BD`

  Bangladesh

* `BE`

  Belgium

* `BF`

  Burkina Faso

* `BG`

  Bulgaria

* `BH`

  Bahrain

* `BI`

  Burundi

* `BJ`

  Benin

* `BL`

  Saint Barthélemy

* `BM`

  Bermuda

* `BN`

  Brunei

* `BO`

  Bolivia

* `BQ`

  Caribbean Netherlands (Bonaire, Sint Eustatius, and Saba)

* `BR`

  Brazil

* `BS`

  Bahamas

* `BT`

  Bhutan

* `BV`

  Bouvet Island

* `BW`

  Botswana

* `BZ`

  Belize

* `CA`

  Canada

* `CC`

  Cocos Islands

* `CG`

  Republic of Congo

* `CH`

  Switzerland

* `CI`

  Côte d'Ivoire (Ivory Coast)

* `CK`

  Cook Islands

* `CL`

  Chile

* `CM`

  Cameroon

* `CN`

  China

* `CO`

  Colombia

* `CR`

  Costa Rica

* `CV`

  Cape Verde

* `CW`

  Curaçao

* `CX`

  Christmas Island

* `CY`

  Cyprus

* `CZ`

  Czechia (Czech Republic)

* `DE`

  Germany

* `DJ`

  Djibouti

* `DK`

  Denmark

* `DM`

  Dominica

* `DO`

  Dominican Republic

* `DZ`

  Algeria

* `EC`

  Ecuador

* `EE`

  Estonia

* `EG`

  Egypt

* `EH`

  Western Sahara

* `ER`

  Eritrea

* `ES`

  Spain

* `ET`

  Ethiopia

* `FI`

  Finland

* `FJ`

  Fiji

* `FK`

  Falkland Islands

* `FM`

  Micronesia

* `FO`

  Faroe Islands

* `FR`

  France

* `GA`

  Gabon

* `GB`

  United Kingdom

* `GD`

  Grenada

* `GE`

  Georgia

* `GF`

  French Guiana

* `GG`

  Guernsey

* `GH`

  Ghana

* `GI`

  Gibraltar

* `GL`

  Greenland

* `GM`

  Gambia

* `GN`

  Guinea

* `GP`

  Guadeloupe

* `GQ`

  Equatorial Guinea

* `GR`

  Greece

* `GS`

  South Georgia and the South Sandwich Islands

* `GT`

  Guatemala

* `GU`

  Guam

* `GW`

  Guinea-Bissau

* `GY`

  Guyana

* `HK`

  Hong Kong

* `HM`

  Heard Island and McDonald Islands

* `HN`

  Honduras

* `HR`

  Croatia

* `HU`

  Hungary

* `ID`

  Indonesia

* `IE`

  Ireland

* `IL`

  Israel

* `IM`

  Isle of Man

* `IN`

  India

* `IO`

  British Indian Ocean Territory

* `IQ`

  Iraq

* `IS`

  Iceland

* `IT`

  Italy

* `JE`

  Jersey

* `JM`

  Jamaica

* `JO`

  Jordan

* `JP`

  Japan

* `KE`

  Kenya

* `KG`

  Kyrgyzstan

* `KH`

  Cambodia

* `KI`

  Kiribati

* `KM`

  Comoros

* `KN`

  Saint Kitts and Nevis

* `KR`

  South Korea

* `KW`

  Kuwait

* `KY`

  Cayman Islands

* `KZ`

  Kazakhstan

* `LA`

  Lao People's Democratic Republic (Laos)

* `LB`

  Lebanon

* `LC`

  Saint Lucia

* `LI`

  Liechtenstein

* `LK`

  Sri Lanka

* `LR`

  Liberia

* `LS`

  Lesotho

* `LT`

  Lithuania

* `LU`

  Luxembourg

* `LV`

  Latvia

* `MA`

  Morocco

* `MC`

  Monaco

* `MD`

  Moldova

* `ME`

  Montenegro

* `MF`

  Saint Martin

* `MG`

  Madagascar

* `MH`

  Marshall Islands

* `MK`

  Macedonia

* `MN`

  Mongolia

* `MO`

  Macao

* `MP`

  Northern Mariana Islands

* `MQ`

  Martinique

* `MR`

  Mauritania

* `MS`

  Montserrat

* `MT`

  Malta

* `MU`

  Mauritius

* `MV`

  Maldives

* `MW`

  Malawi

* `MX`

  Mexico

* `MY`

  Malaysia

* `MZ`

  Mozambique

* `NA`

  Namibia

* `NC`

  New Caledonia

* `NE`

  Niger

* `NF`

  Norfolk Island

* `NG`

  Nigeria

* `NL`

  Netherlands

* `NO`

  Norway

* `NP`

  Nepal

* `NR`

  Nauru

* `NU`

  Niue

* `NZ`

  New Zealand

* `OM`

  Oman

* `PA`

  Panama

* `PE`

  Peru

* `PF`

  French Polynesia

* `PG`

  Papua New Guinea

* `PH`

  Philippines

* `PK`

  Pakistan

* `PL`

  Poland

* `PM`

  Saint Pierre and Miquelon

* `PN`

  Pitcairn

* `PR`

  Puerto Rico

* `PS`

  Palestinian territories

* `PT`

  Portugal

* `PW`

  Palau

* `PY`

  Paraguay

* `QA`

  Qatar

* `RE`

  Reunion

* `RO`

  Romania

* `RS`

  Republic of Serbia

* `RW`

  Rwanda

* `SA`

  Saudi Arabia

* `SB`

  Solomon Islands

* `SC`

  Seychelles

* `SE`

  Sweden

* `SG`

  Singapore

* `SH`

  Saint Helena

* `SI`

  Slovenia

* `SJ`

  Svalbard and Jan Mayen

* `SK`

  Slovakia

* `SL`

  Sierra Leone

* `SM`

  San Marino

* `SN`

  Senegal

* `SR`

  Suriname

* `ST`

  São Tomé and Príncipe

* `SV`

  El Salvador

* `SX`

  Sint Maarten

* `SZ`

  Swaziland

* `TC`

  Turks and Caicos Islands

* `TD`

  Chad

* `TF`

  French Southern and Antarctic Lands

* `TG`

  Togo

* `TH`

  Thailand

* `TJ`

  Tajikistan

* `TK`

  Tokelau

* `TL`

  Timor-Leste

* `TM`

  Turkmenistan

* `TN`

  Tunisia

* `TO`

  Tonga

* `TR`

  Turkey

* `TT`

  Trinidad and Tobago

* `TV`

  Tuvalu

* `TW`

  Taiwan

* `TZ`

  Tanzania

* `UA`

  Ukraine

* `UG`

  Uganda

* `UM`

  United States Minor Outlying Islands

* `US`

  United States

* `UY`

  Uruguay

* `UZ`

  Uzbekistan

* `VA`

  Holy See (Vatican City)

* `VC`

  Saint Vincent and the Grenadines

* `VG`

  British Virgin Islands

* `VI`

  U.S. Virgin Islands

* `VN`

  Vietnam

* `VU`

  Vanuatu

* `WF`

  Wallis and Futuna

* `WS`

  Samoa

* `XK`

  Kosovo

* `YT`

  Mayotte

* `ZA`

  South Africa

* `ZM`

  Zambia

Country code (unsupported)

Two-letter code for a country that Paddle doesn't support sales to. Typically an ISO 3166-1 alpha-2 code, though Paddle uses a small number of codes that ISO doesn't currently assign.

Values

* `AF`

  Afghanistan

* `AQ`

  Antarctica

* `AN`

  Netherlands Antilles. Withdrawn ISO 3166-1 code, succeeded by BQ, CW and SX.

* `BY`

  Belarus

* `CD`

  Democratic Republic of Congo

+ Show all values

* `CF`

  Central African Republic

* `CU`

  Cuba

* `CS`

  Serbia and Montenegro. Withdrawn ISO 3166-1 code, succeeded by RS and ME.

* `HT`

  Haiti

* `IR`

  Iran

* `KP`

  North Korea

* `LY`

  Libya

* `ML`

  Mali

* `MM`

  Myanmar (Burma)

* `NI`

  Nicaragua

* `RU`

  Russia

* `SO`

  Somalia

* `SS`

  South Sudan

* `SD`

  Sudan

* `SY`

  Syria

* `VE`

  Venezuela

* `YE`

  Yemen

* `ZW`

  Zimbabwe

unit_priceobjectrequired

Override price. This price applies to customers located in the countries for this unit price override.

amountstringrequired

Amount in the lowest denomination for the currency, e.g. 10 USD = 1000 (cents). Although represented as a string, this value must be a valid integer.

currency_codestring

Supported three-letter ISO 4217 currency code.

Values

* `USD`

  United States Dollar

* `EUR`

  Euro

* `GBP`

  Pound Sterling

* `JPY`

  Japanese Yen

* `AUD`

  Australian Dollar

+ Show all values

* `CAD`

  Canadian Dollar

* `CHF`

  Swiss Franc

* `HKD`

  Hong Kong Dollar

* `SGD`

  Singapore Dollar

* `SEK`

  Swedish Krona

* `ARS`

  Argentine Peso

* `BRL`

  Brazilian Real

* `CLP`

  Chilean Peso

* `CNY`

  Chinese Yuan

* `COP`

  Colombian Peso

* `CZK`

  Czech Koruna

* `DKK`

  Danish Krone

* `HUF`

  Hungarian Forint

* `ILS`

  Israeli Shekel

* `INR`

  Indian Rupee

* `KRW`

  South Korean Won

* `MXN`

  Mexican Peso

* `NOK`

  Norwegian Krone

* `NZD`

  New Zealand Dollar

* `PEN`

  Peruvian Sol

* `PLN`

  Polish Zloty

* `RUB`

  Russian Ruble

* `THB`

  Thai Baht

* `TRY`

  Turkish Lira

* `TWD`

  New Taiwan Dollar

* `UAH`

  Ukrainian Hryvnia

* `VND`

  Vietnamese Dong

* `ZAR`

  South African Rand

tax_modestring

Default: `account_setting`

How tax is calculated for this price.

Values

* `account_setting`

  Prices use the setting from your account.

* `external`

  Prices are exclusive of tax.

* `internal`

  Prices are inclusive of tax.

* `location`

  Prices are inclusive or exclusive of tax, depending on the country of the transaction.

unit_priceobjectrequired

Base price. This price applies to all customers, except for customers located in countries where you have `unit_price_overrides`.

amountstringrequired

Amount in the lowest denomination for the currency, e.g. 10 USD = 1000 (cents). Although represented as a string, this value must be a valid integer.

currency_codestringrequired

Supported three-letter ISO 4217 currency code.

Values

* `USD`

  United States Dollar

* `EUR`

  Euro

* `GBP`

  Pound Sterling

* `JPY`

  Japanese Yen

* `AUD`

  Australian Dollar

+ Show all values

* `CAD`

  Canadian Dollar

* `CHF`

  Swiss Franc

* `HKD`

  Hong Kong Dollar

* `SGD`

  Singapore Dollar

* `SEK`

  Swedish Krona

* `ARS`

  Argentine Peso

* `BRL`

  Brazilian Real

* `CLP`

  Chilean Peso

* `CNY`

  Chinese Yuan

* `COP`

  Colombian Peso

* `CZK`

  Czech Koruna

* `DKK`

  Danish Krone

* `HUF`

  Hungarian Forint

* `ILS`

  Israeli Shekel

* `INR`

  Indian Rupee

* `KRW`

  South Korean Won

* `MXN`

  Mexican Peso

* `NOK`

  Norwegian Krone

* `NZD`

  New Zealand Dollar

* `PEN`

  Peruvian Sol

* `PLN`

  Polish Zloty

* `RUB`

  Russian Ruble

* `THB`

  Thai Baht

* `TRY`

  Turkish Lira

* `TWD`

  New Taiwan Dollar

* `UAH`

  Ukrainian Hryvnia

* `VND`

  Vietnamese Dong

* `ZAR`

  South African Rand

unit_price_overridesarray

List of unit price overrides. Use to override the base price with a custom price and currency for a country or group of countries.

Items: max 250

country_codesarrayrequired

Supported two-letter ISO 3166-1 alpha-2 country code. Customers located in the listed countries are charged the override price.

Items: min 1, unique

Country code (supported)

Two-letter code for a country that Paddle supports sales to. Typically an ISO 3166-1 alpha-2 code, though Paddle uses a small number of codes that ISO doesn't currently assign.

Values

* `AD`

  Andorra

* `AE`

  United Arab Emirates

* `AG`

  Antigua and Barbuda

* `AI`

  Anguilla

* `AL`

  Albania

+ Show all values

* `AM`

  Armenia

* `AO`

  Angola

* `AR`

  Argentina

* `AS`

  American Samoa

* `AT`

  Austria

* `AU`

  Australia

* `AW`

  Aruba

* `AX`

  Åland Islands

* `AZ`

  Azerbaijan

* `BA`

  Bosnia and Herzegovina

* `BB`

  Barbados

* `BD`

  Bangladesh

* `BE`

  Belgium

* `BF`

  Burkina Faso

* `BG`

  Bulgaria

* `BH`

  Bahrain

* `BI`

  Burundi

* `BJ`

  Benin

* `BL`

  Saint Barthélemy

* `BM`

  Bermuda

* `BN`

  Brunei

* `BO`

  Bolivia

* `BQ`

  Caribbean Netherlands (Bonaire, Sint Eustatius, and Saba)

* `BR`

  Brazil

* `BS`

  Bahamas

* `BT`

  Bhutan

* `BV`

  Bouvet Island

* `BW`

  Botswana

* `BZ`

  Belize

* `CA`

  Canada

* `CC`

  Cocos Islands

* `CG`

  Republic of Congo

* `CH`

  Switzerland

* `CI`

  Côte d'Ivoire (Ivory Coast)

* `CK`

  Cook Islands

* `CL`

  Chile

* `CM`

  Cameroon

* `CN`

  China

* `CO`

  Colombia

* `CR`

  Costa Rica

* `CV`

  Cape Verde

* `CW`

  Curaçao

* `CX`

  Christmas Island

* `CY`

  Cyprus

* `CZ`

  Czechia (Czech Republic)

* `DE`

  Germany

* `DJ`

  Djibouti

* `DK`

  Denmark

* `DM`

  Dominica

* `DO`

  Dominican Republic

* `DZ`

  Algeria

* `EC`

  Ecuador

* `EE`

  Estonia

* `EG`

  Egypt

* `EH`

  Western Sahara

* `ER`

  Eritrea

* `ES`

  Spain

* `ET`

  Ethiopia

* `FI`

  Finland

* `FJ`

  Fiji

* `FK`

  Falkland Islands

* `FM`

  Micronesia

* `FO`

  Faroe Islands

* `FR`

  France

* `GA`

  Gabon

* `GB`

  United Kingdom

* `GD`

  Grenada

* `GE`

  Georgia

* `GF`

  French Guiana

* `GG`

  Guernsey

* `GH`

  Ghana

* `GI`

  Gibraltar

* `GL`

  Greenland

* `GM`

  Gambia

* `GN`

  Guinea

* `GP`

  Guadeloupe

* `GQ`

  Equatorial Guinea

* `GR`

  Greece

* `GS`

  South Georgia and the South Sandwich Islands

* `GT`

  Guatemala

* `GU`

  Guam

* `GW`

  Guinea-Bissau

* `GY`

  Guyana

* `HK`

  Hong Kong

* `HM`

  Heard Island and McDonald Islands

* `HN`

  Honduras

* `HR`

  Croatia

* `HU`

  Hungary

* `ID`

  Indonesia

* `IE`

  Ireland

* `IL`

  Israel

* `IM`

  Isle of Man

* `IN`

  India

* `IO`

  British Indian Ocean Territory

* `IQ`

  Iraq

* `IS`

  Iceland

* `IT`

  Italy

* `JE`

  Jersey

* `JM`

  Jamaica

* `JO`

  Jordan

* `JP`

  Japan

* `KE`

  Kenya

* `KG`

  Kyrgyzstan

* `KH`

  Cambodia

* `KI`

  Kiribati

* `KM`

  Comoros

* `KN`

  Saint Kitts and Nevis

* `KR`

  South Korea

* `KW`

  Kuwait

* `KY`

  Cayman Islands

* `KZ`

  Kazakhstan

* `LA`

  Lao People's Democratic Republic (Laos)

* `LB`

  Lebanon

* `LC`

  Saint Lucia

* `LI`

  Liechtenstein

* `LK`

  Sri Lanka

* `LR`

  Liberia

* `LS`

  Lesotho

* `LT`

  Lithuania

* `LU`

  Luxembourg

* `LV`

  Latvia

* `MA`

  Morocco

* `MC`

  Monaco

* `MD`

  Moldova

* `ME`

  Montenegro

* `MF`

  Saint Martin

* `MG`

  Madagascar

* `MH`

  Marshall Islands

* `MK`

  Macedonia

* `MN`

  Mongolia

* `MO`

  Macao

* `MP`

  Northern Mariana Islands

* `MQ`

  Martinique

* `MR`

  Mauritania

* `MS`

  Montserrat

* `MT`

  Malta

* `MU`

  Mauritius

* `MV`

  Maldives

* `MW`

  Malawi

* `MX`

  Mexico

* `MY`

  Malaysia

* `MZ`

  Mozambique

* `NA`

  Namibia

* `NC`

  New Caledonia

* `NE`

  Niger

* `NF`

  Norfolk Island

* `NG`

  Nigeria

* `NL`

  Netherlands

* `NO`

  Norway

* `NP`

  Nepal

* `NR`

  Nauru

* `NU`

  Niue

* `NZ`

  New Zealand

* `OM`

  Oman

* `PA`

  Panama

* `PE`

  Peru

* `PF`

  French Polynesia

* `PG`

  Papua New Guinea

* `PH`

  Philippines

* `PK`

  Pakistan

* `PL`

  Poland

* `PM`

  Saint Pierre and Miquelon

* `PN`

  Pitcairn

* `PR`

  Puerto Rico

* `PS`

  Palestinian territories

* `PT`

  Portugal

* `PW`

  Palau

* `PY`

  Paraguay

* `QA`

  Qatar

* `RE`

  Reunion

* `RO`

  Romania

* `RS`

  Republic of Serbia

* `RW`

  Rwanda

* `SA`

  Saudi Arabia

* `SB`

  Solomon Islands

* `SC`

  Seychelles

* `SE`

  Sweden

* `SG`

  Singapore

* `SH`

  Saint Helena

* `SI`

  Slovenia

* `SJ`

  Svalbard and Jan Mayen

* `SK`

  Slovakia

* `SL`

  Sierra Leone

* `SM`

  San Marino

* `SN`

  Senegal

* `SR`

  Suriname

* `ST`

  São Tomé and Príncipe

* `SV`

  El Salvador

* `SX`

  Sint Maarten

* `SZ`

  Swaziland

* `TC`

  Turks and Caicos Islands

* `TD`

  Chad

* `TF`

  French Southern and Antarctic Lands

* `TG`

  Togo

* `TH`

  Thailand

* `TJ`

  Tajikistan

* `TK`

  Tokelau

* `TL`

  Timor-Leste

* `TM`

  Turkmenistan

* `TN`

  Tunisia

* `TO`

  Tonga

* `TR`

  Turkey

* `TT`

  Trinidad and Tobago

* `TV`

  Tuvalu

* `TW`

  Taiwan

* `TZ`

  Tanzania

* `UA`

  Ukraine

* `UG`

  Uganda

* `UM`

  United States Minor Outlying Islands

* `US`

  United States

* `UY`

  Uruguay

* `UZ`

  Uzbekistan

* `VA`

  Holy See (Vatican City)

* `VC`

  Saint Vincent and the Grenadines

* `VG`

  British Virgin Islands

* `VI`

  U.S. Virgin Islands

* `VN`

  Vietnam

* `VU`

  Vanuatu

* `WF`

  Wallis and Futuna

* `WS`

  Samoa

* `XK`

  Kosovo

* `YT`

  Mayotte

* `ZA`

  South Africa

* `ZM`

  Zambia

Country code (unsupported)

Two-letter code for a country that Paddle doesn't support sales to. Typically an ISO 3166-1 alpha-2 code, though Paddle uses a small number of codes that ISO doesn't currently assign.

Values

* `AF`

  Afghanistan

* `AQ`

  Antarctica

* `AN`

  Netherlands Antilles. Withdrawn ISO 3166-1 code, succeeded by BQ, CW and SX.

* `BY`

  Belarus

* `CD`

  Democratic Republic of Congo

+ Show all values

* `CF`

  Central African Republic

* `CU`

  Cuba

* `CS`

  Serbia and Montenegro. Withdrawn ISO 3166-1 code, succeeded by RS and ME.

* `HT`

  Haiti

* `IR`

  Iran

* `KP`

  North Korea

* `LY`

  Libya

* `ML`

  Mali

* `MM`

  Myanmar (Burma)

* `NI`

  Nicaragua

* `RU`

  Russia

* `SO`

  Somalia

* `SS`

  South Sudan

* `SD`

  Sudan

* `SY`

  Syria

* `VE`

  Venezuela

* `YE`

  Yemen

* `ZW`

  Zimbabwe

unit_priceobjectrequired

Override price. This price applies to customers located in the countries for this unit price override.

amountstringrequired

Amount in the lowest denomination for the currency, e.g. 10 USD = 1000 (cents). Although represented as a string, this value must be a valid integer.

currency_codestringrequired

Supported three-letter ISO 4217 currency code.

Values

* `USD`

  United States Dollar

* `EUR`

  Euro

* `GBP`

  Pound Sterling

* `JPY`

  Japanese Yen

* `AUD`

  Australian Dollar

+ Show all values

* `CAD`

  Canadian Dollar

* `CHF`

  Swiss Franc

* `HKD`

  Hong Kong Dollar

* `SGD`

  Singapore Dollar

* `SEK`

  Swedish Krona

* `ARS`

  Argentine Peso

* `BRL`

  Brazilian Real

* `CLP`

  Chilean Peso

* `CNY`

  Chinese Yuan

* `COP`

  Colombian Peso

* `CZK`

  Czech Koruna

* `DKK`

  Danish Krone

* `HUF`

  Hungarian Forint

* `ILS`

  Israeli Shekel

* `INR`

  Indian Rupee

* `KRW`

  South Korean Won

* `MXN`

  Mexican Peso

* `NOK`

  Norwegian Krone

* `NZD`

  New Zealand Dollar

* `PEN`

  Peruvian Sol

* `PLN`

  Polish Zloty

* `RUB`

  Russian Ruble

* `THB`

  Thai Baht

* `TRY`

  Turkish Lira

* `TWD`

  New Taiwan Dollar

* `UAH`

  Ukrainian Hryvnia

* `VND`

  Vietnamese Dong

* `ZAR`

  South African Rand

quantityobject

Example: `{"minimum":1,"maximum":100}`

Limits on how many times the related product can be purchased at this price. Useful for discount campaigns. If omitted, defaults to 1-100.

minimumintegerrequired

Default: `1`

Example: `1`

Minimum quantity of the product related to this price that can be bought. Required if `maximum` set.

Range: 1–999999999

maximumintegerrequired

Default: `100`

Example: `100`

Maximum quantity of the product related to this price that can be bought. Required if `minimum` set. Must be greater than or equal to the `minimum` value.

Range: 1–999999999

allowancesarrayearly access

Usage that this price grants for your usage balance units each billing period. Only allowed on recurring prices without a trial period. Transactions and subscriptions with these prices must be collected automatically, and can't use prorated billing modes when they change. At most one allowance for each usage balance unit, and at most 20 allowances in total. If omitted, this price doesn't grant any usage.

Items: max 20, unique

usage_balance_unit_idstringrequired

Example: `ubu_01kw8ta2j8x5t7q4v9m1kd6phz`

Paddle ID of the usage balance unit that this price grants.

Pattern: `^ubu_[a-z\d]{26}$`

amountstringrequired

Amount of the usage balance unit that this price grants each billing period. Paddle multiplies it by the item quantity. If `"0"`, customers get a usage balance with nothing in it, so all their usage is recorded as overage.

Max length: 45

Pattern: `^(0|[1-9][0-9]{0,34})(\.[0-9]{1,9})?$`

custom_dataobject | null

Example: `{"customer_reference_id":"abcd1234"}`

Your own structured key-value data.

productobjectrequired

Product object for a non-catalog item to charge for.

namestringrequired

Name of this product.

Length: 1–200

descriptionstring | null

Short description for this product.

Max length: 2048

tax_categorystringrequired

Tax category for this product. Used for charging the correct rate of tax. Selected tax category must be enabled on your Paddle account.

Values

* `digital-goods`

  Non-customizable digital files or media (not software) acquired with an up front payment that can be accessed without any physical product being delivered.

* `ebooks`

  Digital books and educational material which is sold with permanent rights for use by the customer.

* `implementation-services`

  Remote configuration, set-up, and integrating software on behalf of a customer.

* `professional-services`

  Services that involve the application of your expertise and specialized knowledge of a software product.

* `saas`

  Products that allow users to connect to and use online or cloud-based applications over the Internet.

+ Show all values

* `software-programming-services`

  Services that can be used to customize and white label software products.

* `standard`

  Software products that are pre-written and can be downloaded and installed onto a local device.

* `training-services`

  Training and education services related to software products.

* `website-hosting`

  Cloud storage service for personal or corporate information, assets, or intellectual property.

image_url

Image for this product. Included in the checkout and on some customer documents.

Image Url

A URL to an image.

nullEmpty String

custom_dataobject | null

Example: `{"customer_reference_id":"abcd1234"}`

Your own structured key-value data.

checkoutobject | null

Paddle Checkout details for this transaction. You may pass a URL when creating or updating an automatically-collected transaction, or when creating or updating a manually-collected transaction where `billing_details.enable_checkout` is `true`.

urlstring | null

Checkout URL to use for the payment link for this transaction. Pass the URL for an approved domain, or `null` to set to your default payment URL.

Paddle returns a unique payment link composed of the URL passed or your default payment URL + `?_ptxn=` and the Paddle ID for this transaction.

Length: 1–2048
