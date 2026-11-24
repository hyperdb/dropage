<?php
/**
 * Plugin Name: Dropage
 * Description: ZIPにまとめた静的Webページを /extras に公開します。
 * Version: 1.0.0
 * Requires PHP: 8.2
 */

namespace Dropage;

defined('ABSPATH') || exit;

require_once __DIR__ . '/storage.php';

const CAPABILITY = 'manage_options';

function can_manage(): bool
{
    return !is_multisite() && current_user_can(CAPABILITY) && current_user_can('unfiltered_html');
}

function admin_url_for_page(): string
{
    return admin_url('tools.php?page=dropage');
}

add_action('admin_menu', static function (): void {
    if (!can_manage()) {
        return;
    }
    $hook = add_management_page('Dropage', 'Dropage', CAPABILITY, 'dropage', __NAMESPACE__ . '\\render_page');
    add_action('load-' . $hook, static function (): void {
        wp_enqueue_script('dropage', plugins_url('admin.js', __FILE__), [], '1.0.0', true);
        wp_enqueue_style('dropage', plugins_url('admin.css', __FILE__), [], '1.0.0');
    });
});

function render_page(): void
{
    if (!can_manage()) {
        wp_die('この操作を行う権限がありません。', '', ['response' => 403]);
    }

    $notice = get_transient('dropage_notice_' . get_current_user_id());
    delete_transient('dropage_notice_' . get_current_user_id());
    $pages = [];
    try {
        $root = storage_root();
        if (is_dir($root)) {
            $pages = published_pages($root);
        }
    } catch (\RuntimeException $exception) {
        $notice = [false, $exception->getMessage()];
    }
    ?>
    <div class="wrap">
        <h1>Dropage</h1>
        <?php if (is_array($notice)) : ?>
            <div class="notice <?php echo $notice[0] ? 'notice-success' : 'notice-error'; ?>">
                <p><?php echo esc_html($notice[1]); ?></p>
            </div>
        <?php endif; ?>
        <?php if (class_exists(\ZipArchive::class)) : ?>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="dropage_upload">
                <?php wp_nonce_field('dropage_upload'); ?>
                <div id="dropage-zone">
                    <label for="dropage-archive">ZIPファイルをここにドロップ、または選択してください。</label>
                    <input id="dropage-archive" type="file" name="archive" accept=".zip,application/zip" required>
                </div>
                <?php submit_button('公開する'); ?>
            </form>
        <?php else : ?>
            <div class="notice notice-error"><p>ZIPの公開には PHP の zip 拡張が必要です。</p></div>
        <?php endif; ?>
        <h2>公開済みページ</h2>
        <?php if (!$pages) : ?>
            <p>公開済みページはありません。</p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr><th scope="col">ディレクトリ</th><th scope="col">操作</th></tr></thead>
                <tbody>
                <?php foreach ($pages as $name => $entry) : ?>
                    <tr>
                        <td><a href="<?php echo esc_url(home_url('/' . PUBLIC_DIRECTORY . '/' . encode_path((string) $name . '/' . $entry))); ?>">
                            <?php echo esc_html((string) $name); ?>
                        </a></td>
                        <td>
                            <form method="post" class="dropage-delete" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="dropage_delete">
                                <input type="hidden" name="directory" value="<?php echo esc_attr((string) $name); ?>">
                                <?php wp_nonce_field('dropage_delete_' . $name); ?>
                                <button type="submit" class="button">削除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

function verify_request(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !can_manage()) {
        wp_die('この操作を行う権限がありません。', '', ['response' => 403]);
    }
}

function finish_request(bool $success, string $message): never
{
    set_transient('dropage_notice_' . get_current_user_id(), [$success, $message], MINUTE_IN_SECONDS);
    wp_safe_redirect(admin_url_for_page(), 303);
    exit;
}

add_action('admin_post_dropage_upload', static function (): void {
    verify_request();
    check_admin_referer('dropage_upload');

    try {
        $upload = $_FILES['archive'] ?? [];
        if (($upload['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null)
            || !is_uploaded_file($upload['tmp_name']) || !is_string($upload['name'] ?? null)) {
            throw new \RuntimeException('ZIPを受信できませんでした。ファイルとサーバーのアップロード上限を確認してください。');
        }
        if (!preg_match('/^(.+)\.zip$/iuD', $upload['name'], $match) || !valid_component($match[1])) {
            throw new \RuntimeException('公開先に使用できる名前のZIPファイルを選択してください。');
        }
        publish_archive($upload['tmp_name'], $match[1]);
        finish_request(true, 'ページを公開しました。');
    } catch (\RuntimeException $exception) {
        finish_request(false, $exception->getMessage());
    }
});

add_action('admin_post_dropage_delete', static function (): void {
    verify_request();
    $name = isset($_POST['directory']) && is_string($_POST['directory'])
        ? wp_unslash($_POST['directory']) : '';
    check_admin_referer('dropage_delete_' . $name);

    try {
        unpublish($name);
        finish_request(true, 'ページを削除しました。');
    } catch (\RuntimeException $exception) {
        finish_request(false, $exception->getMessage());
    }
});
