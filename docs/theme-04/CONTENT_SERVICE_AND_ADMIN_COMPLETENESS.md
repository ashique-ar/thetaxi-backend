# Theme 04 content, service, and admin completeness

Theme 04 presents About, Contact/Inquiry, FAQ, Point-to-point, Corporate Transfers, Rate Chart, CMS index/show/search/featured, and every inquiry service-builder section through the existing route/controller/view owners. Its route-scoped styling remains derived from the canonical `D:\projects\cassons\thetaxi\b5aef749-7fa6-43da-a0b8-2d5ba017eb72.png` reference without copying reference branding or content.

`resources/views/blogs.blade.php` and `resources/views/blog-detail.blade.php` are legacy, unrouted templates. Live blog content is owned by the named `cms.index` and `cms.show` routes and their `resources/views/cms/*` views. The legacy files remain untouched for compatibility and are not counted as live theme surfaces.

Website Settings receives selector metadata from `config/website_themes.php`. Theme 04 remains excluded while `WEBSITE_THEME_04_ENABLED=false`; write validation rejects an unreleased identifier, and the established active-theme cache key is invalidated on an accepted update.

