<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
</head>
<body>
    <div class="wrapper">
        <div class="hero-mesh">
            <div class="container">
                <div class="header">
                    <h2><?= htmlspecialchars($list->title ?? 'List') ?></h2>
                    <?php if (!empty($list->description)): ?>
                        <p class="description"><?= htmlspecialchars($list->description) ?></p>
                    <?php endif; ?>
                    <span class="badge">Total items: <?= count($items) ?></span>
                </div>

                <?php if (count($items) > 0): ?>
                <div class="items-list">
                    <div class="items-heading">Top Items</div>
                    
                    <?php 
                    $limit = min(5, count($items));
                    for ($i = 0; $i < $limit; $i++): 
                        $item = $items[$i];
                    ?>
                    <table class="item-table" width="100%" cellpadding="0" cellspacing="0" border="0">
                        <tr>
                            <td class="item-td-artwork" valign="middle">
                                <div class="item-artwork">
                                    <?php if (!empty($item['artwork_url'])): ?>
                                        <img src="<?= htmlspecialchars($item['artwork_url']) ?>" alt="Artwork" />
                                    <?php else: ?>
                                        <div class="artwork-placeholder"></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td valign="middle" class="item-td-details">
                                <div class="item-title"><?= htmlspecialchars($item['podcast_name'] ?? 'Unknown Podcast') ?></div>
                                <div class="item-author"><?= htmlspecialchars($item['podcast_author'] ?? 'Unknown Author') ?></div>
                            </td>
                        </tr>
                    </table>
                    <?php endfor; ?>
                    
                    <?php if (count($items) > 5): ?>
                        <div class="more-items">
                            ...and <?= count($items) - 5 ?> more
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="footer">
                    <p>Your full exported list is attached to this email.</p>
                    <p class="branding">Million Podcasts</p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
