It's time to move forward with the plugin review "aixeiger"!

Your plugin is not yet ready to be approved, you are receiving this email because the volunteers have manually checked it and have found some issues in the code / functionality of your plugin.

Please check this email thoroughly, address any issues listed, test your changes, and upload a corrected version of your code if all is well.

List of issues found

🔴 Trialware and Locked Features
Please review your plugin to ensure that it does not include any locked or restricted built-in functionality. This is not permitted under the WordPress.org Plugin Directory Guidelines you agreed to when submitting the plugin.

❌ Guideline 5 – Trialware
Plugins must be fully functional. You may not:
Lock, disable or limit built-in features behind a license key, trial period, usage limit, time, quota or any other kind of intended restriction.

Even if the locked feature is present in the code "just in case the user upgrades," it’s still not allowed. Your plugin may point out which features are available through a separated plugin, but that's it. All plugin code hosted on WordPress.org must be free and fully functional.

🌐 Guideline 6 – Serviceware
Plugins may connect to a legitimate external service to perform certain functionality, provided:
The service performs actual processing on external servers.
The functionality provided cannot be done locally by the plugin.
The service is clearly documented in your readme, including Terms of Use and Privacy Policy links.

For example: a "Spam checker" plugin that connects to a external service to check for spam (and thus uses it to provide that functionality) is generally acceptable. A plugin that simply checks a license key to unlock local features is not.

✅ Ask yourself:
Does any function only work after a license check or payment?
Is any functionality in the plugin code disabled or limited until it’s unlocked?
Are there any limitations on the plugin after a certain amount of time or usage?

After excluding functionalities provided by legitimate external services, if the answer is yes to any of the above, the plugin does not comply.

🔧 How to fix it:
Remove all license checks or other mechanisms that control access to features built in in the plugin code.
Remove or fully enable any built in features that are currently locked or limited.
Make sure external services are compliant and clearly documented.

ℹ️ Important clarification:
WordPress.org is not a marketplace. It's a repository for free, fully functional, GPL-compliant plugins.

If you are not offering a service and want to offer additional features through a paid version, that code must be:
Hosted elsewhere (e.g., your own website).
Not included in the plugin hosted on WordPress.org.
GPL compliant: Do not include any mechanisms that would prevent a plug-in from being used after a license has been checked.

✨ Yes — the codebase contains intentionally restricted functionality: tag-based HTML/fragment invalidation and per-table SQL invalidation are implemented/gated in runtime code, while the free build falls back to global flush/global epochs and loads the needed modules only in the Pro package      


## Determine files and directories locations correctly

WordPress provides several functions for easily determining where a given file or directory lives.

We detected that the way your plugin references some files, directories and/or URLs may not work with all WordPress setups. This happens because there are hardcoded references or you are using the WordPress internal constants.

Let's improve it, please check out the following documentation:

https://developer.wordpress.org/plugins/plugin-basics/determining-plugin-and-content-directories/

It contains all the functions available to determine locations correctly.

Most common cases in plugins can be solved using the following functions:
For where your plugin is located: plugin_dir_path() , plugin_dir_url() , plugins_url()
For the uploads directory: wp_upload_dir() (Note: If you need to write files, please do so in a folder in the uploads directory, not in your plugin directories).

Example(s) from your plugin:
includes/dropin/object-cache.php:21 define( 'PIGCACHE_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins/pigcache' );
includes/class-pigcache-dropin-html-cache.php:176 dirname( ABSPATH ) . '/wp-config.php',
includes/dropin/advanced-cache.php:20 $_pigcache_matches = glob( WP_CONTENT_DIR . '/plugins/*/includes/class-pigcache-html-cache.php' );
 -----> WP_CONTENT_DIR
includes/dropin/advanced-cache.php:11 WP_CONTENT_DIR . '/mu-plugins/pigcache/includes/class-pigcache-html-cache.php',
includes/dropin/advanced-cache.php:10 WP_CONTENT_DIR . '/plugins/pigcache/includes/class-pigcache-html-cache.php',
includes/dropin/object-cache.php:2902 $mofile = WP_LANG_DIR . "/plugins/{$domain}-{$locale}.mo";
includes/class-pigcache-dropin-object-cache.php:118 if ( ! is_writable( WP_CONTENT_DIR ) ) {



ℹ️ In order to determine your plugin location, you would need to use the __FILE__ variable for this to work properly.
Note that this variable depends on the location of the file making the call. As this can create confusion, a common practice is to save its value in a define() in the main file of your plugin so that you don't have to worry about this.

Example: Your main plugin file.
define( 'PIGCAC_PLUGIN_FILE', __FILE__ );
define( 'PIGCAC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PIGCAC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

Example: Any file of your plugin.
require_once PIGCAC_PLUGIN_DIR . 'admin/class-init.php';


function pigcac_scripts() {
 wp_enqueue_script( 'pigcac-script', PIGCAC_PLUGIN_URL . 'js/script.js', array(), PIGCAC_VERSION );
 // Or alternatively
 wp_enqueue_script( 'pigcac-script', plugins_url( 'js/script.js', PIGCAC_PLUGIN_FILE ), array(), PIGCAC_VERSION );
}
add_action( 'wp_enqueue_scripts', 'pigcac_scripts' );


Example(s) from your plugin:
includes/dropin/object-cache.php:21 define( 'PIGCACHE_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins/pigcache' );
includes/dropin/advanced-cache.php:20 $_pigcache_matches = glob( WP_CONTENT_DIR . '/plugins/*/includes/class-pigcache-html-cache.php' );
... out of a total of 3 incidences.


## Saving data in the plugin folder and/or asking users to edit/write to plugin.

We cannot accept a plugin that forces (or tells) users to edit the plugin files in order to function, or saves data in the plugin folder.

Plugin folders are deleted when upgraded, so using them to store any data is problematic. Also bear in mind, any data saved in a plugin folder is accessible by the public. This means anyone can read it and use it without the site-owner’s permission.

It is preferable that you save your information to the database, via the Settings API, especially if it’s privileged data.

If that’s not possible, because you’re uploading media files, you should use the media uploader.

If you can’t do either of those, you must save the data outside the plugins folder. We recommend using the uploads directory, creating a folder there with the slug of your plugin as name, as that will make your plugin compatible with multisite and other one-off configurations.

Please refer to the following links:

https://developer.wordpress.org/plugins/settings/
https://developer.wordpress.org/reference/functions/media_handle_upload/
https://developer.wordpress.org/reference/functions/wp_handle_upload/
https://developer.wordpress.org/reference/functions/wp_upload_dir/

Example(s) from your plugin:
includes/dropin/object-cache.php:2857 file_put_contents($this->pigcache_circuit_path(), (string) time());
# ✨ Writes a circuit-breaker flag file to the system temp directory via sys_get_temp_dir(), which is outside the allowed WordPress/plugin write locations.
pigcache.php:101 file_put_contents($logs_dir . '/.gitignore', "*\n!.gitignore\n!.htaccess\n");
# ↳ Detected: plugin_dir_path
# ✨ Writes .gitignore into the plugin’s own logs directory under the plugin folder, which is not an allowed storage location.
pigcache.php:100 file_put_contents($logs_dir . '/.htaccess', "Order deny,allow\nDeny from all\n");
# ↳ Detected: plugin_dir_path
# ✨ Writes .htaccess into a logs directory inside the plugin folder, and storing writable data in the plugin directory is not allowed.



## Unclosed ob_start()

Using ob_start() without explicitly closing the buffer within the same logical flow (e.g., using ob_get_clean() , ob_end_flush() , or similar) can lead to unpredictable behaviour.

While output buffering is a valid technique, you should not leave a buffer 'open'.

WordPress is a shared environment in which the core, plugins and themes run in a coordinated sequence that isn't always predictable, particularly due to the way hooks work. If another component opens or closes a buffer that doesn't align with yours, the buffer stack can become misaligned, resulting in unexpected behaviour.

If you need to modify the entire response output, you can do so in a standardised way since WordPress 6.9 using the template enhancement output buffer.

Please ensure that every instance of ob_start() you create is paired with a corresponding closing function (like ob_get_clean() ) within the same function scope, and that nothing (including hooks and your code logic) can intercept or bypass that closing logic.

Example(s) from your plugin:
includes/class-pigcache-html-cache.php:122 ob_start(array(__CLASS__, 'ob_callback'));
# ✨ ob_start() is opened in maybe_start_buffer() with ob_callback() present, but no explicit ob_end_*/ob_get_clean() close is found in the same logical flow, leaving the buffer to implicit shutdown handling.  



## Generic function/class/define/namespace/option names

All plugins must have unique function names, namespaces, defines, class and option names. This prevents your plugin from conflicting with other plugins or themes. We need you to update your plugin to use more unique and distinct names.

A good way to do this is with a prefix. For example, if your plugin is called "PigCache" then you could use names like these:
function pigcac_save_post(){ ... }
class PIGCAC_Admin { ... }
update_option( 'pigcac_options', $options );
add_shortcode( 'pigcac_shortcode', $callback );
register_setting( 'pigcac_settings', 'pigcac_user_id', ... );
define( 'PIGCAC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
global $pigcac_options;
add_action('wp_ajax_pigcac_save_data', ... );
namespace aixeiger\pigcache;

Disclaimer: These are just examples that may have been self-generated from your plugin name, we trust you can find better options. If you have a good alternative, please use it instead, this is just an example.

The prefix should be at least four (4) characters long (don't try to use two- or three-letter prefixes anymore). We host almost 100,000 plugins on WordPress.org alone. There are tens of thousands more outside our servers. Believe us, you're likely to encounter conflicts.

You also need to avoid the use of __ (double underscores), wp_ , or _ (single underscore) as a prefix. Those are reserved for WordPress itself. You can use them inside your classes, but not as stand-alone function.

Please remember, if you're using _n() or __() for translation, that's fine. We're only talking about functions you've created for your plugin, not the core functions from WordPress. In fact, those core features are why you need to not use those prefixes in your own plugin! You don't want to break WordPress for your users.

Related to this, using if (!function_exists('NAME')) { around all your functions and classes sounds like a great idea until you realize the fatal flaw. If something else has a function with the same name and their code loads first, your plugin will break. Using if-exists should be reserved for shared libraries only.

Remember: Good prefix names are unique and distinct to your plugin. This will help you and the next person in debugging, as well as prevent conflicts.

Analysis result:
# This plugin is using the prefix "redis" for 12 element(s).
# This plugin is using the prefixes "pigcache", "pig_cache" for 42 element(s).

# Using the common word "wp" as a prefix.
includes/dropin/object-cache.php:378 class WP_Object_Cache
