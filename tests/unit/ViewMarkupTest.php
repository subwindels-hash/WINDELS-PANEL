<?php
use PHPUnit\Framework\TestCase;

/**
 * ViewMarkupTest — the markup the *browser* parses, not the template we wrote.
 *
 * A view can be perfectly valid PHP and still hand the browser a document it
 * cannot render. The failure this class exists for:
 *
 *     <script <?=csp_nonce_attr()?>
 *     // comment
 *     (function () { … })();
 *     </script>
 *
 * The ">" that closes the start tag is missing. csp_nonce_attr() returns a
 * *complete attribute*, so the line reads as finished — and PHP eats the
 * newline after `?>`, so nothing in the source betrays it.
 *
 * What the browser does is much worse than "the script is broken". The
 * tokenizer keeps consuming the attributes, then the script body, then
 * everything the template emits after it, looking for the ">" that ends the
 * start tag. It finds the one in `</script>`, emits the script *element*, and
 * switches to script-data state. From there every byte of the page is script
 * text until a literal `</script>` shows up — and the only one in the document
 * was already eaten as an attribute.
 *
 * So the navigation, <main>, the footer and the closing tags are parsed as
 * script data and never rendered. The visitor sees the top strip of the page
 * over an empty body, the tab never settles, and the operator reports "the
 * website stopped loading after I uploaded it". PHP, MySQL and the web server
 * are all completely healthy, which is why this is invisible from the
 * application side: /health/live answers 200, deploy-verify.php reports
 * READY, and the response body really does contain the whole page — the
 * browser simply refuses to see it as markup.
 *
 * That exact bug shipped in partials/announcement_bar.php, which is in the
 * global header, so it took down every public page at once.
 *
 * The check walks each template the way a tokenizer would — stepping over PHP
 * blocks in place, so line numbers stay the author's — and fails any <script>
 * start tag that reaches a newline or another tag before it reaches ">".
 */
class ViewMarkupTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
    }

    /** Every template that emits markup the browser has to parse. */
    private function templates()
    {
        $out = array();
        foreach (array('application/views', 'application/controllers') as $dir) {
            $path = self::$root.'/'.$dir;
            if (!is_dir($path)) continue;
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') $out[] = $f->getPathname();
            }
        }
        return $out;
    }

    public function testEveryInlineScriptStartTagIsClosed()
    {
        $broken = array();
        $tags = 0;

        foreach ($this->templates() as $file) {
            foreach ($this->scriptStartTags((string)file_get_contents($file)) as $tag) {
                $tags++;
                if ($tag['closed']) continue;
                $broken[] = str_replace(self::$root.'/', '', $file).':'.$tag['line'].' — '.$tag['why'];
            }
        }

        // A scan that finds no <script> at all is a broken scan, not a pass.
        $this->assertGreaterThan(10, $tags, 'the scan found almost no <script> tags — it is looking in the wrong place');

        $this->assertSame(array(), $broken,
            "These <script> start tags are never closed with '>'. Everything after them — the\n"
            ."navigation, the page body, the footer — is parsed as script text and never rendered,\n"
            ."so the page looks like it stopped loading. Add the '>' at the end of the start tag.");
    }

    /**
     * The homepage shell is the one that took the whole site down, so it is
     * asserted separately: it must exist and must not be able to open a
     * script element it never closes.
     */
    public function testTheGlobalHeaderOpensNoUnclosedScript()
    {
        $bar = self::$root.'/application/views/partials/announcement_bar.php';
        $this->assertFileExists($bar);

        foreach ($this->scriptStartTags((string)file_get_contents($bar)) as $tag) {
            $this->assertTrue($tag['closed'],
                'partials/announcement_bar.php:'.$tag['line'].' — an unclosed <script> here blanks every public page ('.$tag['why'].')');
        }
    }

    /**
     * Walk a template like an HTML tokenizer would.
     *
     * @return array[] of {line, closed, why} — one entry per <script> in the file
     */
    private function scriptStartTags($src)
    {
        $out = array();
        $len = strlen($src);
        $from = 0;

        while (($lt = strpos($src, '<script', $from)) !== false) {
            // Only a real tag: <script, <script … or <script>. Not <scriptx,
            // and not the word inside a string.
            $next = $lt + 7 < $len ? $src[$lt + 7] : '>';
            if ($next !== ' ' && $next !== "\t" && $next !== "\n" && $next !== "\r" && $next !== '>') {
                $from = $lt + 7;
                continue;
            }

            $j = $lt + 7;
            $closed = false;
            $why = null;

            while ($j < $len) {
                $ch = $src[$j];

                if ($ch === '>') { $closed = true; break; }

                // Step over a PHP block. It can span lines and can never be
                // the ">" that closes the tag: these helpers emit attributes
                // and escaped text, never markup.
                if ($ch === '<' && substr($src, $j, 2) === '<?') {
                    $end = strpos($src, '?>', $j + 2);
                    $j = $end === false ? $len : $end + 2;
                    // A closing PHP tag eats one following newline, so the
                    // next line's text is glued to the output — which is
                    // exactly how a missing ">" hides.
                    if (isset($src[$j]) && $src[$j] === "\r") $j++;
                    if (isset($src[$j]) && $src[$j] === "\n") $j++;
                    continue;
                }

                if ($ch === '<')              { $why = 'the start tag swallows the markup that follows it'; break; }
                if ($ch === "\n" || $ch === "\r") { $why = 'the start tag runs onto the next line'; break; }

                $j++;
            }

            if (!$closed && $why === null) {
                $why = 'the start tag is never closed';
            }

            $out[] = array(
                'line'   => substr_count(substr($src, 0, $lt), "\n") + 1,
                'closed' => $closed,
                'why'    => (string)$why,
            );

            $from = $lt + 7;
        }

        return $out;
    }
}
