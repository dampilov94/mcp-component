# Access control (ACL)

Order matters — most links are by id, so list first to get ids.

- **Users**: `modx_list_users` / `get` / `create_user` (pass `password`; profile fields like
  `email`, `fullname`) / `update_user` / `delete_user`.
- **User groups / roles**: `modx_list_user_groups`, `modx_create_user_group`, `modx_list_roles`,
  `modx_create_role`; membership via `modx_add_user_to_group {user, usergroup, role}`.
- **Policies**: `modx_list_access_policies`, `modx_create_access_policy {name, template}`.
- **Resource groups**: `modx_create_resource_group`, `modx_assign_resource_to_group {resource, resourceGroup}`.
- **Access**: `modx_grant_context_access {principal:<usergroup id>, target:<context key>, policy, authority}`
  and `modx_grant_resourcegroup_access {principal, target:<resourcegroup id>, policy, authority}`.

ACL writes auto-flush permissions; `modx_flush_permissions` does it manually. Enable the **access**
capability group if these tools are missing.
