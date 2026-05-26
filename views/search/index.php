<?php
// Initial query is read on the client too, but pre-render it into the input
// so a deep-linked share-URL doesn't briefly show an empty box before JS runs.
$q = trim((string) ($_GET['q'] ?? ''));
?>
<style>
.search-page { max-width: 900px; margin: 0 auto; padding: 24px 20px 80px; }
.search-page h1 { margin: 0 0 12px; font-size: 22px; }
.search-page-form { display: flex; gap: 8px; margin: 0 0 24px; }
.search-page-input {
    flex: 1;
    padding: 10px 14px;
    border: 2px solid var(--color-border);
    border-radius: 6px;
    font-size: 15px;
    font-family: var(--font-family);
}
.search-page-input:focus { outline: none; border-color: var(--color-primary); }
.search-page-status { color: var(--color-text-light); font-size: 13px; margin: -12px 0 18px; }
.search-page-group { margin: 0 0 26px; }
.search-page-group-title {
    font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em;
    color: var(--color-text-light);
    margin: 0 0 8px;
}
.search-result {
    display: block;
    padding: 10px 12px;
    border: 1px solid var(--color-border);
    border-radius: 6px;
    text-decoration: none;
    color: inherit;
    background: #fff;
    margin-bottom: 8px;
    transition: box-shadow 0.1s;
}
.search-result:hover { box-shadow: 0 2px 6px rgba(0,0,0,0.08); }
.search-result-title { font-weight: 600; font-size: 14px; margin-bottom: 2px; }
.search-result-snippet { color: var(--color-text-light); font-size: 13px; line-height: 1.4; }
.search-result-snippet mark { background: #fff2a8; color: inherit; padding: 0 1px; border-radius: 2px; }
.search-result-meta { color: var(--color-text-light); font-size: 11px; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.03em; }
.search-empty { color: var(--color-text-light); padding: 40px 0; text-align: center; }
</style>
<div class="search-page" id="searchPage" data-initial-query="<?php echo htmlspecialchars($q, ENT_QUOTES); ?>">
    <h1>Search</h1>
    <form class="search-page-form" id="searchPageForm" autocomplete="off">
        <input type="text" id="searchPageInput" name="q" class="search-page-input"
               placeholder="Search cards, descriptions, checklist items, comments..."
               value="<?php echo htmlspecialchars($q, ENT_QUOTES); ?>" autofocus>
        <button type="submit" class="btn btn-primary">Search</button>
    </form>
    <div class="search-page-status" id="searchPageStatus"></div>
    <div id="searchPageResults"></div>
</div>
<script src="<?php echo asset_url('public/js/search_page.js'); ?>"></script>
