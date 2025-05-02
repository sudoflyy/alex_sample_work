<?php

namespace Drupal\ipc_syncdb\Traits;

use Drupal\profile\Entity\Profile;

/**
 * Helpers when you sre working with Profile.
 */
trait TransactionProfileTrait {

  /**
   * Sets the profile fields with values retrieved via API call.
   *
   * @param \Drupal\profile\Entity\Profile $profile
   *   The profile.
   * @param array $address_from_response
   *   The address from the Api response.
   */
  protected function setProfileFields(Profile $profile, array $address_from_response) {
    $profile->set('netsuite_id', $address_from_response['nsAddressId']);
    $address = [
      'country_code' => $address_from_response['country']['twoLetterISOCode'],
      'address_line1' => $address_from_response['address1'],
      'address_line2' => $address_from_response['address2'],
      'locality' => $address_from_response['city'],
      'administrative_area' => $address_from_response['stateOrProvince'],
      'postal_code' => $address_from_response['postalCode'],
      'organization' => $address_from_response['addressee'],
    ];
    $profile->set('address', $address);
    if ($address_from_response['primaryAddress'] == TRUE) {
      $profile->set('address_type', 'primary');
    }
    $profile->set('primary_address', $address_from_response['primaryAddress']);
    $profile->set('default_billing_address', $address_from_response['defaultBillingAddress']);
    $profile->set('default_shipping_address', $address_from_response['defaultShippingAddress']);
    $profile->set('home_address', $address_from_response['homeAddress']);
    $profile->set('residential_address', $address_from_response['residentialAddress']);
    $profile->set('phone_number', $address_from_response['phone']);
    $profile->set('address_attention_to', $address_from_response['attention']);
    $profile->save();
  }

}
