<?php

class CRM_Sepa_Logic_Format_mbankpl extends CRM_Sepa_Logic_Format {

  public static $settings = array(
    'nip' => '6762472999',
    'zleceniodawca_nazwa' => 'Fundacja Kupuj Odpowiedzialnie',
    'zleceniodawca_adres1' => 'ul. Sławkowska 12',
    'zleceniodawca_adres2' => '31-014 Kraków',
  );

  public function getDDFilePrefix() {
    return 'BRE-';
  }

  public function getFilename($variable_string) {
    return $variable_string . '.txt';
  }

}
