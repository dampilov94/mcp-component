# miniShop2

- **Products** are resources with `class_key: "msProduct"`. Create with `modx_create_element`
  (`type:"resource"`, `data.class_key:"msProduct"`, `parent:<category id>`). Commerce fields
  (`price`, `old_price`, `article`, `vendor`, `remains`, …) are set as resource fields.
- **Categories**: `modx_ms2_create_category` / `update` / `modx_ms2_list_categories` (msCategory).
- **Options (характеристики)**: `modx_ms2_list_option_types`, `modx_ms2_list_options`,
  `modx_ms2_create_option`, `modx_ms2_assign_option_to_category`; per-product values via
  `modx_ms2_get_product_options` / `modx_ms2_update_product_options`.
- **Links (связи товаров)**: first a link *type* (`modx_ms2_create_link_type` with relation
  `many_to_many`/`one_to_many`/`many_to_one`/`one_to_one`), then link products with
  `modx_ms2_create_product_link {link, master, slave}`. List via `modx_ms2_list_product_links`.
- **Orders**: `modx_ms2_list_orders`, `modx_ms2_get_order`, `modx_ms2_update_order` (status change
  triggers ms2 logic).

Enable the **miniShop2** capability group if these tools are missing.
