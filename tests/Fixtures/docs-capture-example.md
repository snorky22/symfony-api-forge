## Response (200)

dataarrayrequired

idstringrequired

Example: `pri_01gsz8z1q1n00f12qt82y31smh`

Unique ID for this item.

Pattern: `^pri_[a-z\d]{26}$`

typestringrequired

Default: `standard`

Type of item.

Values

*   `custom`
    
    Non-catalog item.
    
*   `standard`
    
    Standard item.
    

namestring | nullrequired

Name of this item.

Length: 1–150

trial_periodobject | nullrequired

Trial period. `null` for no trial period.

intervalstringrequired

Unit of time.

Values

*   `day`
*   `week`

unit_priceobject | null

Trial price.

amountstringrequired

Amount in the lowest denomination for the currency.

currency_codestring

Supported three-letter ISO 4217 currency code.

Values

*   `USD`
    
    United States Dollar
    
*   `EUR`
    
    Euro
    
+ Show all values

unit_priceobjectrequired

Base price.

amountstringrequired

Amount.

currency_codestringrequired

Currency.

custom_dataobject | nullrequired

Your own data.

allowancesarrayearly access

Usage allowances.

usage_unit_idstringrequired

Id of the unit.

amountstringrequired

Allowance.

productobject

Related product, returned with include.

idstringrequired

Product id.

namestringrequired

Product name.

metaobjectrequired

Information about this response.

nextstring (uri)required

URL of the next page.

estimated_totalinteger

Estimated number of items.
