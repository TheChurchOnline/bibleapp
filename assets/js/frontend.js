/**
 * The Church Online Bible Reader — Frontend JS
 * v1.6.1
 */
(function($) {
    'use strict';

    var CBR = {
        versionId: 0,
        bookId: 0,
        chapterId: 0,
        totalChapters: 0,
        fontSize: 0,
        loading: false,
        // Current verse range being displayed (0 = full chapter)
        verseStart: 0,
        verseEnd: 0,
        // Paginate mode
        page: 1,
        pageCount: 1,
        pendingLastPage: false, // when going backwards into the previous chapter

        init: function() {
            this.versionId = parseInt(cbrData.defaultVersion) || 3;
            this.bookId    = parseInt(cbrData.defaultBook) || 1;
            this.chapterId = parseInt(cbrData.defaultChapter) || 1;
            this.fontSize  = parseInt(localStorage.getItem('cbr_font_size')) || 0;

            // Deep link: #John.3.16 or #Genesis.8.15-20 or #1+Corinthians.13 overrides the defaults
            this.applyHash(window.location.hash);

            if (localStorage.getItem('cbr_dark') === '1') {
                $('#cbr-bible-reader').addClass('cbr-dark');
            }

            this.applyFontSize();
            this.populateChapterSelect();
            this.loadChapter();
            this.buildBookBrowser();
            this.bindEvents();
        },

        bindEvents: function() {
            var self = this;

            $('#cbr-version-select').on('change', function() {
                self.versionId = parseInt($(this).val());
                self.loadChapter(); // keeps current verse range if any
            });

            $('#cbr-book-select').on('change', function() {
                self.bookId = parseInt($(this).val());
                self.chapterId = 1;
                self.page = 1;
                self.clearRange();
                self.populateChapterSelect();
                self.loadChapter();
            });

            $('#cbr-chapter-select').on('change', function() {
                self.chapterId = parseInt($(this).val());
                self.page = 1;
                self.clearRange();
                self.loadChapter();
            });

            // Verse dropdown: 0 = whole chapter, N = that single verse
            $('#cbr-verse-select').on('change', function() {
                var v = parseInt($(this).val()) || 0;
                if (v > 0) { self.verseStart = v; self.verseEnd = v; } else { self.clearRange(); }
                self.loadChapter();
            });

            $('#cbr-prev-chapter, #cbr-prev-chapter-bottom').on('click', function() { self.prevChapter(); });
            $('#cbr-next-chapter, #cbr-next-chapter-bottom').on('click', function() { self.nextChapter(); });

            $('#cbr-search-btn').on('click', function() { self.doSearch(); });
            $('#cbr-search-input').on('keypress', function(e) {
                if (e.which === 13) { e.preventDefault(); self.doSearch(); }
            });
            $('#cbr-search-close').on('click', function() { self.hideSearch(); });

            $('#cbr-dark-toggle').on('click', function() {
                var $r = $('#cbr-bible-reader');
                $r.toggleClass('cbr-dark');
                localStorage.setItem('cbr_dark', $r.hasClass('cbr-dark') ? '1' : '0');
            });

            $('#cbr-font-up').on('click', function() { self.fontSize = Math.min(self.fontSize + 2, 14); self.applyFontSize(); });
            $('#cbr-font-down').on('click', function() { self.fontSize = Math.max(self.fontSize - 2, -6); self.applyFontSize(); });

            // "Continue reading" (expand mode)
            $('#cbr-content').on('click', '.cbr-continue-btn', function() {
                var $c = $('#cbr-content');
                $c.find('.cbr-rest-verses').removeAttr('hidden');
                $c.find('.cbr-preview-verses').removeClass('cbr-preview-verses');
                $(this).closest('.cbr-continue-wrap').remove();
            });

            // "View full chapter" link: in paginate mode, open the page that contains the verse
            $('#cbr-content').on('click', '.cbr-view-full-chapter', function(e) {
                e.preventDefault();
                var v = self.verseStart;
                self.clearRange();
                self.page = (self.isPaginated() && v > 0) ? Math.ceil(v / self.perPage()) : 1;
                self.loadChapter();
            });

            // Pager (paginate mode)
            $('#cbr-content').on('click', '.cbr-page-prev', function() { self.gotoPage(self.page - 1); });
            $('#cbr-content').on('click', '.cbr-page-next', function() { self.gotoPage(self.page + 1); });

            // Reset to the page's default reference
            $('#cbr-reset').on('click', function() { self.resetToDefault(); });

            // Manual hash edits / back-forward between references
            $(window).on('hashchange', function() {
                if (self._settingHash) return;
                if (self.applyHash(window.location.hash)) {
                    self.hideSearch();
                    self.populateChapterSelect();
                    self.loadChapter();
                }
            });

            $(document).on('keydown', function(e) {
                if ($(e.target).is('input, select, textarea')) return;
                if (self.isPaginated() && self.pageCount > 1) {
                    if (e.key === 'ArrowLeft')  self.gotoPage(self.page - 1);
                    if (e.key === 'ArrowRight') self.gotoPage(self.page + 1);
                    return;
                }
                if (e.key === 'ArrowLeft')  self.prevChapter();
                if (e.key === 'ArrowRight') self.nextChapter();
            });
        },

        clearRange: function() { this.verseStart = 0; this.verseEnd = 0; },

        isPaginated: function() { return cbrData.lengthMode === 'paginate'; },
        perPage: function() { return Math.max(1, parseInt(cbrData.perPage) || 7); },

        /** Move within the paginated chapter; crosses chapter boundaries at either end. */
        gotoPage: function(n) {
            if (!this.isPaginated()) return;
            if (n < 1) {
                if (this.chapterId > 1) { this.pendingLastPage = true; this.prevChapter(); }
                return;
            }
            if (n > this.pageCount) {
                if (this.chapterId < this.totalChapters) { this.page = 1; this.nextChapter(); }
                return;
            }
            this.page = n;
            this.renderPage();
        },

        /** Re-render the current chapter's page from the cached verse list (no AJAX). */
        renderPage: function() {
            if (this._chapterData) this.renderPassage(this._chapterData);
        },

        /**
         * Disable every control while content is loading; re-enable when done.
         * Uses the real disabled attribute (keyboard + screen readers) plus a class for styling.
         */
        setBusy: function(busy) {
            var $r = $('#cbr-bible-reader');
            $r.toggleClass('cbr-busy', !!busy).attr('aria-busy', busy ? 'true' : 'false');
            $r.find('.cbr-select, .cbr-search, .cbr-btn, .cbr-nav-btn, .cbr-book-btn').prop('disabled', !!busy);
            if (!busy) {
                // Restore the chapter-boundary state the busy flag just overwrote
                $('#cbr-prev-chapter, #cbr-prev-chapter-bottom').prop('disabled', this.chapterId <= 1);
                $('#cbr-next-chapter, #cbr-next-chapter-bottom').prop('disabled', this.chapterId >= this.totalChapters);
            }
        },

        /**
         * Parse "#Book.Chapter[.Verse[-Verse]]" (spaces as + or %20 or _) into reader state.
         * Returns true if the hash named a valid book.
         */
        applyHash: function(hash) {
            if (!hash || hash.length < 2) return false;
            var raw;
            try { raw = decodeURIComponent(hash.substring(1)); } catch (e) { raw = hash.substring(1); }
            var parts = raw.split('.');
            if (parts.length < 2) return false;

            var bookName = parts[0].replace(/[+_]/g, ' ').trim();
            var bookId = this.findBookId(bookName);
            if (!bookId) return false;

            var chapter = parseInt(parts[1]) || 1;
            var vs = 0, ve = 0;
            this.page = 1;
            if (parts[2] && /^p\d+$/i.test(parts[2])) {
                this.page = parseInt(parts[2].substring(1)) || 1;
                parts[2] = null;
            }
            if (parts[2]) {
                var vr = parts[2].split(/[-\u2013\u2014]/);
                vs = parseInt(vr[0]) || 0;
                ve = parseInt(vr[1]) || vs;
                if (ve < vs) ve = vs;
            }

            this.bookId = bookId;
            this.chapterId = chapter;
            this.verseStart = vs;
            this.verseEnd = ve;
            $('#cbr-book-select').val(bookId);
            return true;
        },

        /** Match a book name (exact, then prefix) against the book dropdown. */
        findBookId: function(name) {
            var target = name.toLowerCase().replace(/\s+/g, '');
            if (!target) return 0;
            var exact = 0, prefix = 0;
            $('#cbr-book-select option').each(function() {
                var n = $(this).text().toLowerCase().replace(/\s+/g, '');
                if (n === target) { exact = parseInt($(this).val()); return false; }
                if (!prefix && n.indexOf(target) === 0) prefix = parseInt($(this).val());
            });
            return exact || prefix;
        },

        /** Clear search/filters and return to the shortcode's default reference. */
        resetToDefault: function() {
            this.versionId = parseInt(cbrData.defaultVersion) || 3;
            this.bookId    = parseInt(cbrData.defaultBook) || 1;
            this.chapterId = parseInt(cbrData.defaultChapter) || 1;
            this.page = 1;
            this.clearRange();
            $('#cbr-version-select').val(this.versionId);
            $('#cbr-book-select').val(this.bookId);
            $('#cbr-search-input').val('');
            $('#cbr-book-browser').slideUp(150);
            this.hideSearch();
            this.populateChapterSelect();
            this.loadChapter();
        },

        hideSearch: function() {
            $('#cbr-search-results').hide();
            $('#cbr-content, .cbr-chapter-header, .cbr-bottom-nav').show();
        },

        populateChapterSelect: function() {
            var $sel = $('#cbr-chapter-select');
            var total = parseInt($('#cbr-book-select').find(':selected').data('chapters')) || 1;
            this.totalChapters = total;
            $sel.empty();
            for (var i = 1; i <= total; i++) {
                $sel.append('<option value="' + i + '"' + (i === this.chapterId ? ' selected' : '') + '>Chapter ' + i + '</option>');
            }
        },

        populateVerseSelect: function(count) {
            var $sel = $('#cbr-verse-select');
            var current = (this.verseStart > 0 && this.verseEnd === this.verseStart) ? this.verseStart : 0;
            var html = '<option value="0">All verses</option>';
            for (var i = 1; i <= count; i++) {
                html += '<option value="' + i + '"' + (i === current ? ' selected' : '') + '>Verse ' + i + '</option>';
            }
            $sel.html(html);
            if (current === 0) $sel.val('0');
        },

        /**
         * Load the current book/chapter (and verse range, if set) from the server.
         */
        loadChapter: function() {
            if (this.loading) return;
            this.loading = true;

            var self = this;
            self.setBusy(true);
            $('#cbr-content').html('<div class="cbr-loading"><div class="cbr-spinner"></div></div>');

            $.post(cbrData.ajaxUrl, {
                action:      'cbr_get_chapter',
                nonce:       cbrData.nonce,
                version_id:  self.versionId,
                book_id:     self.bookId,
                chapter_id:  self.chapterId,
                verse_start: self.verseStart,
                verse_end:   self.verseEnd
            }, function(res) {
                self.loading = false;
                if (!res.success) {
                    $('#cbr-content').html('<p class="cbr-empty">No verses found. Please import Bible data.</p>');
                    self.setBusy(false);
                    return;
                }
                self.renderPassage(res.data);
                self.setBusy(false);
            }).fail(function() {
                self.loading = false;
                $('#cbr-content').html('<p class="cbr-empty">Could not load this chapter. Please try again.</p>');
                self.setBusy(false);
            });
        },

        /**
         * Render a passage payload (full chapter or verse range).
         */
        renderPassage: function(d) {
            var self = this;
            var $content = $('#cbr-content');

            self.bookId        = parseInt(d.book_id);
            self.chapterId     = parseInt(d.chapter_id);
            self.totalChapters = parseInt(d.total_chapters);
            self.verseStart    = parseInt(d.verse_start) || 0;
            self.verseEnd      = parseInt(d.verse_end) || 0;

            var isPartial = !!d.is_partial && self.verseStart > 0;
            self._chapterData = d;

            // Paginate mode: slice the chapter into pages of perPage verses
            var paginate = self.isPaginated() && !isPartial && d.verses && d.verses.length > self.perPage();
            if (paginate) {
                self.pageCount = Math.ceil(d.verses.length / self.perPage());
                if (self.pendingLastPage) { self.page = self.pageCount; self.pendingLastPage = false; }
                if (self.page < 1) self.page = 1;
                if (self.page > self.pageCount) self.page = self.pageCount;
            } else {
                self.page = 1; self.pageCount = 1; self.pendingLastPage = false;
            }

            // Title: "Genesis 8" or "Genesis 8:15" or "Genesis 8:15–20"
            var title = d.book_name + ' ' + d.chapter_id;
            if (isPartial) {
                title += ':' + self.verseStart;
                if (self.verseEnd > self.verseStart) title += '\u2013' + self.verseEnd;
            }
            $('#cbr-chapter-title').text(title);
            $('#cbr-page-info').text('Chapter ' + d.chapter_id + ' of ' + d.total_chapters);

            $('#cbr-book-select').val(d.book_id);
            self.populateChapterSelect();
            $('#cbr-chapter-select').val(d.chapter_id);
            self.populateVerseSelect(parseInt(d.verse_count) || (d.verses ? d.verses.length : 0));

            var showNums = cbrData.showVerseNums === '1';
            var html = '';

            // "Continue reading" mode: show a preview, hide the rest until clicked.
            // Never applies to passage searches (they're already short).
            var expandMode = cbrData.lengthMode === 'expand' && !isPartial;
            var previewN   = parseInt(cbrData.previewVerses) || 15;
            var total      = d.verses ? d.verses.length : 0;
            var collapse   = expandMode && total > previewN + 2; // don't hide just 1–2 verses

            function verseHtml(v) {
                var out = '<span class="cbr-verse" data-verse="' + v.verse_id + '">';
                if (showNums) {
                    out += '<sup class="cbr-verse-num" title="' + self.escapeHtml(d.book_name) + ' ' + d.chapter_id + ':' + v.verse_id + '">' + v.verse_id + '</sup>';
                }
                out += '<span class="cbr-verse-text">' + self.escapeHtml(v.verse_text) + '</span></span> ';
                return out;
            }

            var pageVerses = d.verses || [];
            var pageFirst = 0, pageLast = 0;
            if (paginate) {
                var startIdx = (self.page - 1) * self.perPage();
                pageVerses = d.verses.slice(startIdx, startIdx + self.perPage());
                pageFirst = parseInt(pageVerses[0].verse_id);
                pageLast  = parseInt(pageVerses[pageVerses.length - 1].verse_id);
            }

            if (!total && d.importing) {
                var pct = d.importing.percent || 0;
                html = '<div class="cbr-empty cbr-importing">Bible data is still loading (' + pct + '%)&hellip;<br>'
                     + '<span class="cbr-importing-sub">This page will refresh automatically.</span></div>';
                clearTimeout(self._importTimer);
                self._importTimer = setTimeout(function(){ self.loadChapter(); }, 4000);
            } else if (!total) {
                html = '<p class="cbr-empty">No verses found for ' + self.escapeHtml(title) + '.</p>';
            } else if (collapse) {
                html += '<div class="cbr-preview-verses">';
                d.verses.slice(0, previewN).forEach(function(v) { html += verseHtml(v); });
                html += '</div>';
                html += '<div class="cbr-rest-verses" hidden>';
                d.verses.slice(previewN).forEach(function(v) { html += verseHtml(v); });
                html += '</div>';
                html += '<div class="cbr-continue-wrap">'
                      + '<button type="button" class="cbr-btn cbr-btn-nav cbr-continue-btn">Continue reading '
                      + '<span class="cbr-continue-count">(' + (total - previewN) + ' more verses)</span></button>'
                      + '</div>';
            } else if (paginate) {
                pageVerses.forEach(function(v) { html += verseHtml(v); });
                var atStart = self.page === 1 && self.chapterId <= 1;
                var atEnd   = self.page === self.pageCount && self.chapterId >= self.totalChapters;
                html += '<nav class="cbr-pager" aria-label="Verse pages">'
                      + '<button type="button" class="cbr-btn cbr-btn-nav cbr-page-prev"' + (atStart ? ' disabled' : '') + '>\u2190 Previous</button>'
                      + '<span class="cbr-pager-info">Verses ' + pageFirst + '\u2013' + pageLast + ' of ' + total
                      + ' <span class="cbr-pager-page">(page ' + self.page + ' of ' + self.pageCount + ')</span></span>'
                      + '<button type="button" class="cbr-btn cbr-btn-nav cbr-page-next"' + (atEnd ? ' disabled' : '') + '>Next \u2192</button>'
                      + '</nav>';
            } else {
                d.verses.forEach(function(v) { html += verseHtml(v); });
            }

            if (isPartial) {
                html += '<div class="cbr-partial-notice">'
                      + 'Showing ' + self.escapeHtml(title) + '. '
                      + '<a href="#" class="cbr-view-full-chapter">View full chapter ' + d.chapter_id + ' \u2192</a>'
                      + '</div>';
            }

            $content.html(html);

            $('#cbr-prev-chapter, #cbr-prev-chapter-bottom').prop('disabled', self.chapterId <= 1);
            $('#cbr-next-chapter, #cbr-next-chapter-bottom').prop('disabled', self.chapterId >= self.totalChapters);

            self.updateBookBrowserActive();

            // Reset scroll: inner container to top, and bring the reader into view
            $content.scrollTop(0);
            var reader = document.getElementById('cbr-bible-reader');
            if (reader && reader.getBoundingClientRect().top < 0) {
                reader.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }

            if (history.replaceState) {
                var hash = '#' + d.book_name.replace(/\s+/g, '+') + '.' + d.chapter_id;
                if (isPartial) hash += '.' + self.verseStart + (self.verseEnd > self.verseStart ? '-' + self.verseEnd : '');
                else if (paginate && self.page > 1) hash += '.p' + self.page;
                if (window.location.hash !== hash) {
                    self._settingHash = true;
                    history.replaceState(null, null, hash);
                    setTimeout(function(){ self._settingHash = false; }, 50);
                }
            }
        },

        prevChapter: function() {
            if (this.chapterId > 1) {
                this.chapterId--;
                this.clearRange();
                if (!this.pendingLastPage) this.page = 1;
                this.populateChapterSelect();
                this.loadChapter();
            }
        },

        nextChapter: function() {
            if (this.chapterId < this.totalChapters) {
                this.chapterId++;
                this.clearRange();
                this.page = 1;
                this.populateChapterSelect();
                this.loadChapter();
            }
        },

        doSearch: function() {
            var query = $('#cbr-search-input').val().trim();
            if (query.length < 2) return;

            var self = this;
            self.setBusy(true);

            $.post(cbrData.ajaxUrl, {
                action:     'cbr_search',
                nonce:      cbrData.nonce,
                version_id: self.versionId,
                query:      query
            }, function(res) {
                self.setBusy(false);
                if (!res.success) return;
                var d = res.data;

                // ── Passage reference: server already returned exactly the verses requested ──
                if (d.type === 'passage') {
                    self.hideSearch();
                    self.renderPassage(d);
                    $('#cbr-search-input').val('');
                    return;
                }

                // ── Keyword results ──
                $('#cbr-content, .cbr-chapter-header, .cbr-bottom-nav').hide();
                $('#cbr-search-results').show();
                $('#cbr-search-title').text(d.count + ' result' + (d.count === 1 ? '' : 's') + ' for \u201C' + d.query + '\u201D');

                var html = '';
                if (!d.count) {
                    html = '<p class="cbr-empty">No results found.</p>';
                } else {
                    d.results.forEach(function(r) {
                        html += '<div class="cbr-search-item" data-book="' + r.book_id + '" data-chapter="' + r.chapter_id + '" data-verse="' + r.verse_id + '">'
                              + '<div class="cbr-search-ref">' + self.escapeHtml(r.book_name) + ' ' + r.chapter_id + ':' + r.verse_id + '</div>'
                              + '<div class="cbr-search-text">' + self.highlightText(r.verse_text, d.query) + '</div>'
                              + '</div>';
                    });
                }
                $('#cbr-search-list').html(html);

                // Click a result → show that single verse (with "View full chapter" link)
                $('.cbr-search-item').off('click').on('click', function() {
                    self.bookId     = parseInt($(this).data('book'));
                    self.chapterId  = parseInt($(this).data('chapter'));
                    self.verseStart = parseInt($(this).data('verse'));
                    self.verseEnd   = self.verseStart;
                    self.hideSearch();
                    self.populateChapterSelect();
                    self.loadChapter();
                });
            }).fail(function() { self.setBusy(false); });
        },

        buildBookBrowser: function() {
            var otHtml = '', ntHtml = '';
            $('#cbr-book-select option').each(function() {
                var $opt = $(this), id = $opt.val();
                if (!id) return;
                var btn = '<button class="cbr-book-btn" data-book-id="' + id + '" data-chapters="' + $opt.data('chapters') + '">' + $opt.text() + '</button>';
                if (parseInt(id) < 40) otHtml += btn; else ntHtml += btn;
            });
            $('#cbr-ot-books').html(otHtml);
            $('#cbr-nt-books').html(ntHtml);

            var self = this;
            $('.cbr-book-btn').on('click', function() {
                self.bookId = parseInt($(this).data('book-id'));
                self.chapterId = 1;
                self.page = 1;
                self.clearRange();
                $('#cbr-book-select').val(self.bookId);
                self.populateChapterSelect();
                self.loadChapter();
                $('#cbr-book-browser').slideUp(200);
            });

            $('#cbr-chapter-title').css('cursor', 'pointer').on('click', function() {
                $('#cbr-book-browser').slideToggle(200);
            });

            this.updateBookBrowserActive();
        },

        updateBookBrowserActive: function() {
            $('.cbr-book-btn').removeClass('active');
            $('.cbr-book-btn[data-book-id="' + this.bookId + '"]').addClass('active');
        },

        applyFontSize: function() {
            var reader = document.getElementById('cbr-bible-reader');
            var base = parseInt(getComputedStyle(reader).getPropertyValue('--cbr-verse-size')) || 18;
            $('#cbr-content').css('font-size', (base + this.fontSize) + 'px');
            localStorage.setItem('cbr_font_size', this.fontSize);
        },

        highlightText: function(text, query) {
            var safe = this.escapeHtml(text);
            var escaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            return safe.replace(new RegExp('(' + escaped + ')', 'gi'), '<mark>$1</mark>');
        },

        escapeHtml: function(str) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(String(str)));
            return div.innerHTML;
        }
    };

    $(document).ready(function() {
        if ($('#cbr-bible-reader').length) CBR.init();
    });

})(jQuery);
