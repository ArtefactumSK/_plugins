# SQL Audit Report - artefactum-licence-api.php

## KROK 1: Kontrola všetkých $wpdb->prepare() volaní

### ✅ Riadok 176-179: `artefactum_api_license_status_by_uid`
- **Query**: `SELECT domain FROM {$wpdb->clients} WHERE customer_uid = %s LIMIT 1`
- **Placeholders**: 1x %s
- **Parametre**: 1 ($uid)
- **Status**: ✅ OK

### ⚠️ Riadok 194-214: `artefactum_api_license_status_by_uid` - hlavný dotaz
- **Query**: 
  ```sql
  WHERE (domain = %s OR domain = %s OR domain LIKE %s)
  ORDER BY CASE 
    WHEN domain = %s THEN 1
    WHEN domain LIKE %s THEN 2
    ELSE 3
  END
  ```
- **Placeholders**: 5x %s (3 v WHERE, 2 v CASE)
- **Parametre**: 5 ($primary_domain, '*.' . $root_domain, '%.' . $root_domain, $primary_domain, '%.' . $root_domain)
- **Status**: ✅ OK - počet sedí

### ✅ Riadok 230-236: `artefactum_api_license_status_by_uid` - moduly
- **Query**: `WHERE license_id = %d AND status = 'active'`
- **Placeholders**: 1x %d
- **Parametre**: 1 ($licence->id)
- **Status**: ✅ OK

### ✅ Riadok 268-273: `artefactum_api_client_info`
- **Query**: `WHERE customer_uid = %s`
- **Placeholders**: 1x %s
- **Parametre**: 1 ($uid)
- **Status**: ✅ OK

### ✅ Riadok 280-285: `artefactum_api_client_info` - emaily
- **Query**: `WHERE customer_uid = %s`
- **Placeholders**: 1x %s
- **Parametre**: 1 ($uid)
- **Status**: ✅ OK

### ✅ Riadok 287-293: `artefactum_api_client_info` - počet licencií
- **Query**: `WHERE c.customer_uid = %s AND l.status = 'active'`
- **Placeholders**: 1x %s
- **Parametre**: 1 ($uid)
- **Status**: ✅ OK

## KROK 2: Špeciálna kontrola fallback query s CASE ORDER BY

### ✅ Riadok 354-379: Fallback query pre subdomény
- **Query**:
  ```sql
  WHERE domain IN (%s, %s, %s)
  ORDER BY CASE 
    WHEN domain = %s THEN 1
    WHEN domain = %s THEN 2
    WHEN domain = %s THEN 3
  END
  ```
- **Placeholders**: 6x %s (3 v WHERE IN, 3 v CASE)
- **Parametre**: 6 ($domain, $wildcard_domain, $root_domain, $domain, $wildcard_domain, $root_domain)
- **Status**: ✅ OK - presne 6 parametrov ako požadované

### ✅ Riadok 381-393: Fallback query pre root domény
- **Query**:
  ```sql
  WHERE (domain = %s OR domain = %s)
  ORDER BY CASE WHEN domain = %s THEN 1 ELSE 2 END
  ```
- **Placeholders**: 3x %s (2 v WHERE, 1 v CASE)
- **Parametre**: 3 ($domain, $root_domain, $domain)
- **Status**: ✅ OK - presne 3 parametre ako požadované

### ⚠️ POZNÁMKA: Obe fallback query podporujú `$filter_by_email`
- Ak je `$filter_by_email` nastavené, pridáva sa `AND contact_email LIKE %s`
- Parametre sa správne pridávajú do poľa `$params[]`
- **Status**: ✅ OK

### ✅ Riadok 456-461: Načítanie modulov
- **Query**: `WHERE license_id = %d AND status = 'active'`
- **Placeholders**: 1x %d
- **Parametre**: 1 ($licence->id)
- **Status**: ✅ OK

### ✅ Riadok 787-790: Editácia licencie
- **Query**: `WHERE id = %d`
- **Placeholders**: 1x %d
- **Parametre**: 1 ($edit_id)
- **Status**: ✅ OK

### ✅ Riadok 794-797: Editácia modulov
- **Query**: `WHERE license_id = %d ORDER BY id ASC`
- **Placeholders**: 1x %d
- **Parametre**: 1 ($edit_id)
- **Status**: ✅ OK

### ✅ Riadok 827: Toggle status
- **Query**: `WHERE id = %d`
- **Placeholders**: 1x %d
- **Parametre**: 1 ($id)
- **Status**: ✅ OK

### ⚠️ Riadok 844-850: Kontrola duplicity license_key
- **Query**: 
  ```sql
  WHERE license_key = %s
  [conditionally: AND id != %d]
  ```
- **Placeholders**: 1x %s (vždy), + 1x %d (ak $id > 0)
- **Parametre**: 
  - Ak $id > 0: `[$license_key, $id]` (2 parametre)
  - Ak $id == 0: `[$license_key]` (1 parameter)
- **Status**: ✅ OK - podmienené placeholdery sú správne implementované

### ✅ Riadok 870-873: Kontrola existujúcej licencie
- **Query**: `WHERE id = %d`
- **Placeholders**: 1x %d
- **Parametre**: 1 ($id)
- **Status**: ✅ OK

### ✅ Riadok 1290-1293: Zobrazenie modulov v admin
- **Query**: `WHERE license_id = %d AND status = 'active'`
- **Placeholders**: 1x %d
- **Parametre**: 1 ($lic->id)
- **Status**: ✅ OK

### ✅ Riadok 1934-1936: Získanie license keys
- **Query**: `WHERE user_email = %s`
- **Placeholders**: 1x %s
- **Parametre**: 1 ($user->user_email)
- **Status**: ✅ OK

### ✅ Riadok 1976-1979: Kontrola existencie license key
- **Query**: `WHERE license_key = %s`
- **Placeholders**: 1x %s
- **Parametre**: 1 ($key)
- **Status**: ✅ OK

### ✅ Riadok 3143-3146: Kontrola existujúcich modulov
- **Query**: `WHERE license_id = %d`
- **Placeholders**: 1x %d
- **Parametre**: 1 ($lic->id)
- **Status**: ✅ OK

## KROK 3: Kontrola duplicitnej logiky

### Analýza:
1. **Fallback query (riadok 354-393)**: 
   - Používa sa len raz v `$find_core_licence()` funkcii
   - Nie je duplicitná logika
   - ✅ OK

2. **Hlavný dotaz (riadok 194-214)**:
   - Používa sa v `artefactum_api_license_status_by_uid()`
   - Fallback query sa používa v `artefactum_api_check_licence()`
   - Sú to rôzne funkcie s rôznymi účelmi
   - ✅ OK - nie je duplicita

3. **$product_code**:
   - V kóde sa kontroluje existencia stĺpca `product_code` (riadok 344-348)
   - Fallback query explicitne ignoruje `product_code` (komentár na riadku 352)
   - ✅ OK - $product_code nie je ignorovaný zbytočne, ale zámerne

## KROK 4: Overenie normalizácie domén

### Riadok 331-336: `artefactum_api_check_licence()`
```php
$domain = strtolower(preg_replace('/[^a-z0-9\.\-]/i', '', $domain));
$parts = explode('.', $domain);
$root_domain = count($parts) >= 2 ? implode('.', array_slice($parts, -2)) : $domain;
$wildcard_domain = '*.' . $root_domain;
$is_subdomain = count($parts) > 2;
```

**Analýza**:
- ✅ `$domain`: normalizovaná (lowercase, len povolené znaky)
- ✅ `$root_domain`: správne extrahovaný (posledné 2 časti)
- ✅ `$wildcard_domain`: správne vytvorený (`*` + root)
- ✅ `$is_subdomain`: správne detekovaný (viac ako 2 časti)
- ✅ **Žiadne double wildcard** (`*.*.`)
- ✅ **Žiadne duplicitné root matchy** (každá hodnota je unikátna)

### Riadok 186-192: `artefactum_api_license_status_by_uid()`
```php
$stored_domain = $client->domain;
$parts = explode('.', $stored_domain);
$root_domain = count($parts) >= 2 
    ? implode('.', array_slice($parts, -2)) 
    : $stored_domain;
$primary_domain = $root_domain;
```

**Analýza**:
- ✅ `$root_domain`: správne extrahovaný
- ✅ `$primary_domain`: nastavený na `$root_domain`
- ⚠️ **POZNÁMKA**: V dotaze sa používa `'*.' . $root_domain` a `'%.' . $root_domain`, čo je správne

## ZÁVER

### ✅ Všetky prepare() volania majú správny počet placeholderov a parametrov
### ✅ Fallback query s CASE ORDER BY má presne 6 parametrov (subdomain) alebo 3 parametre (root)
### ✅ Nie sú zistené duplicitné dotazy
### ✅ Normalizácia domén je správna, bez double wildcard alebo kolízií

### ⚠️ Nájdené menšie problémy:
1. **Žiadne kritické problémy** - všetky dotazy sú správne
2. **Kód je dobre štruktúrovaný** - fallback logika je jasne oddelená

### Odporúčania:
- Všetky dotazy sú správne implementované
- Kód je pripravený na produkciu
- ORDER BY CASE priorita domén je zachovaná
