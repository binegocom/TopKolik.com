import 'package:flutter/material.dart';
import '../../../../core/theme/topkolik_theme.dart';
import '../../../../core/models/topkolik_domain_models.dart';
import '../../../../core/localization/topkolik_localizations.dart';
import 'slow_mode_banner.dart';

class SegmentedTribuneView extends StatefulWidget {
  final MatchFixture fixture;
  final List<ArenaComment> comments;
  final bool postingEnabled;
  final int slowModeSeconds;
  final String userFavoriteClub; // e.g., 'Galatasaray', 'Fenerbahçe', or empty
  final Function(String section, String body, int? parentCommentId) onPostComment;
  final Function(int commentId, String reason) onReportComment;
  final Function(int targetUserId) onBlockUser;

  const SegmentedTribuneView({
    super.key,
    required this.fixture,
    required this.comments,
    required this.postingEnabled,
    required this.slowModeSeconds,
    this.userFavoriteClub = 'Galatasaray',
    required this.onPostComment,
    required this.onReportComment,
    required this.onBlockUser,
  });

  @override
  State<SegmentedTribuneView> createState() => _SegmentedTribuneViewState();
}

class _SegmentedTribuneViewState extends State<SegmentedTribuneView> {
  String _activeSection = 'neutral'; // 'home', 'away', 'neutral'
  final TextEditingController _textController = TextEditingController();
  final FocusNode _focusNode = FocusNode();
  int? _replyingToCommentId;
  String? _replyingToAuthorName;
  bool _isSlowModeCooling = false;

  @override
  void dispose() {
    _textController.dispose();
    _focusNode.dispose();
    super.dispose();
  }

  bool get _canPostInActiveSection {
    if (_activeSection == 'neutral') return true;
    if (_activeSection == 'home') {
      return widget.userFavoriteClub.toLowerCase().contains(widget.fixture.homeTeamName.toLowerCase()) ||
          widget.fixture.homeTeamName.toLowerCase().contains(widget.userFavoriteClub.toLowerCase());
    }
    if (_activeSection == 'away') {
      return widget.userFavoriteClub.toLowerCase().contains(widget.fixture.awayTeamName.toLowerCase()) ||
          widget.fixture.awayTeamName.toLowerCase().contains(widget.userFavoriteClub.toLowerCase());
    }
    return true;
  }

  void _submit() {
    final text = _textController.text.trim();
    if (text.isEmpty || !_canPostInActiveSection || !widget.postingEnabled || _isSlowModeCooling) {
      return;
    }

    widget.onPostComment(_activeSection, text, _replyingToCommentId);
    _textController.clear();
    setState(() {
      _replyingToCommentId = null;
      _replyingToAuthorName = null;
      if (widget.slowModeSeconds > 0) {
        _isSlowModeCooling = true;
      }
    });
  }

  void _openReportDialog(int commentId) {
    final l10n = TopkolikLocalizations.of(context);
    showDialog(
      context: context,
      builder: (ctx) {
        final reasons = [
          'Yasadışı Bahis / Kumar Reklamı',
          'Korsan Yayın Linki',
          'Hakaret / Şiddet Tehdidi',
          'Spam / Taciz',
        ];
        return AlertDialog(
          backgroundColor: Theme.of(ctx).cardColor,
          title: Text(l10n.translate('report')),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: reasons.map((r) {
              return ListTile(
                dense: true,
                title: Text(r, style: const TextStyle(fontSize: 13)),
                onTap: () {
                  Navigator.of(ctx).pop();
                  widget.onReportComment(commentId, r);
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('Bildiriminiz moderasyon ekibine iletildi.')),
                  );
                },
              );
            }).toList(),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(ctx).pop(),
              child: const Text('İptal'),
            ),
          ],
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    final l10n = TopkolikLocalizations.of(context);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final filteredComments = widget.comments.where((c) => c.section == _activeSection).toList();

    return Column(
      children: [
        // Section Segmented Selector
        Container(
          margin: const EdgeInsets.symmetric(vertical: 8),
          padding: const EdgeInsets.all(4),
          decoration: BoxDecoration(
            color: isDark ? TopkolikTheme.darkSurface : const Color(0xFFE6EBE8),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Row(
            children: [
              _buildSegmentTab(
                sectionKey: 'home',
                label: '${widget.fixture.homeTeamName} Tribünü',
                icon: Icons.shield_outlined,
              ),
              _buildSegmentTab(
                sectionKey: 'neutral',
                label: l10n.translate('tribune_neutral'),
                icon: Icons.forum_outlined,
              ),
              _buildSegmentTab(
                sectionKey: 'away',
                label: '${widget.fixture.awayTeamName} Tribünü',
                icon: Icons.shield_outlined,
              ),
            ],
          ),
        ),

        // Supporter Room Restriction Notice
        if (!_canPostInActiveSection)
          Container(
            margin: const EdgeInsets.only(bottom: 8),
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            decoration: BoxDecoration(
              color: TopkolikTheme.dangerRed.withValues(alpha: isDark ? 0.15 : 0.08),
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: TopkolikTheme.dangerRed.withValues(alpha: 0.4)),
            ),
            child: Row(
              children: [
                const Icon(Icons.lock_outline, size: 16, color: TopkolikTheme.dangerRed),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    l10n.translate('must_follow_club_to_post'),
                    style: TextStyle(
                      fontSize: 12,
                      color: isDark ? const Color(0xFFFF8A94) : TopkolikTheme.dangerRed,
                    ),
                  ),
                ),
              ],
            ),
          ),

        // Slow Mode Cooldown Banner
        if (widget.slowModeSeconds > 0 && _isSlowModeCooling)
          SlowModeBanner(
            cooldownSeconds: widget.slowModeSeconds,
            onCooldownEnded: () {
              setState(() {
                _isSlowModeCooling = false;
              });
            },
          ),

        // Comments List
        Expanded(
          child: filteredComments.isEmpty
              ? Center(
                  child: Text(
                    'Henüz bu tribünde yorum yok. İlk yorumu sen yap!',
                    style: TextStyle(
                      fontSize: 13,
                      color: isDark ? TopkolikTheme.textMutedDark : TopkolikTheme.textMutedLight,
                    ),
                  ),
                )
              : ListView.separated(
                  physics: const BouncingScrollPhysics(),
                  padding: const EdgeInsets.symmetric(vertical: 8),
                  itemCount: filteredComments.length,
                  separatorBuilder: (_, _) => const SizedBox(height: 8),
                  itemBuilder: (context, index) {
                    final comment = filteredComments[index];
                    return _buildCommentCard(comment, isDark, l10n);
                  },
                ),
        ),

        // Reply Indicator Banner
        if (_replyingToAuthorName != null)
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
            decoration: BoxDecoration(
              color: isDark ? TopkolikTheme.darkCard : TopkolikTheme.lightCard,
              border: Border(
                top: BorderSide(
                  color: isDark ? TopkolikTheme.darkBorder : TopkolikTheme.lightBorder,
                ),
              ),
            ),
            child: Row(
              children: [
                const Icon(Icons.reply_rounded, size: 14, color: TopkolikTheme.electricAccent),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    'Yanıtlanıyor: $_replyingToAuthorName',
                    style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
                  ),
                ),
                IconButton(
                  icon: const Icon(Icons.close_rounded, size: 16),
                  onPressed: () {
                    setState(() {
                      _replyingToCommentId = null;
                      _replyingToAuthorName = null;
                    });
                  },
                ),
              ],
            ),
          ),

        // Comment Input Bar
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          decoration: BoxDecoration(
            color: isDark ? TopkolikTheme.darkSurface : TopkolikTheme.lightSurface,
            border: Border(
              top: BorderSide(
                color: isDark ? TopkolikTheme.darkBorder : TopkolikTheme.lightBorder,
              ),
            ),
          ),
          child: SafeArea(
            top: false,
            child: Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _textController,
                    focusNode: _focusNode,
                    enabled: widget.postingEnabled && _canPostInActiveSection && !_isSlowModeCooling,
                    style: TextStyle(
                      fontSize: 14,
                      color: isDark ? TopkolikTheme.textPrimaryDark : TopkolikTheme.textPrimaryLight,
                    ),
                    decoration: InputDecoration(
                      hintText: !_canPostInActiveSection
                          ? 'Sadece favori kulüp taraftarları yazabilir'
                          : l10n.translate('comment_hint'),
                      hintStyle: TextStyle(
                        fontSize: 13,
                        color: isDark ? TopkolikTheme.textMutedDark : TopkolikTheme.textMutedLight,
                      ),
                      filled: true,
                      fillColor: isDark ? TopkolikTheme.darkCard : TopkolikTheme.lightBg,
                      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(20),
                        borderSide: BorderSide.none,
                      ),
                    ),
                    onSubmitted: (_) => _submit(),
                  ),
                ),
                const SizedBox(width: 8),
                IconButton.filled(
                  onPressed: (!widget.postingEnabled || !_canPostInActiveSection || _isSlowModeCooling)
                      ? null
                      : _submit,
                  icon: const Icon(Icons.send_rounded, size: 18),
                  style: IconButton.styleFrom(
                    backgroundColor: TopkolikTheme.electricAccent,
                    foregroundColor: const Color(0xFF072B1A),
                    disabledBackgroundColor: Colors.grey.withValues(alpha: 0.3),
                  ),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }

  Widget _buildSegmentTab({
    required String sectionKey,
    required String label,
    required IconData icon,
  }) {
    final isSelected = _activeSection == sectionKey;
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Expanded(
      child: GestureDetector(
        onTap: () {
          setState(() {
            _activeSection = sectionKey;
          });
        },
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 180),
          padding: const EdgeInsets.symmetric(vertical: 8),
          decoration: BoxDecoration(
            color: isSelected
                ? (isDark ? TopkolikTheme.darkCard : Colors.white)
                : Colors.transparent,
            borderRadius: BorderRadius.circular(10),
            boxShadow: isSelected
                ? [
                    BoxShadow(
                      color: Colors.black.withValues(alpha: 0.08),
                      blurRadius: 4,
                      offset: const Offset(0, 1),
                    )
                  ]
                : null,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                icon,
                size: 14,
                color: isSelected
                    ? TopkolikTheme.electricAccent
                    : (isDark ? TopkolikTheme.textMutedDark : TopkolikTheme.textMutedLight),
              ),
              const SizedBox(height: 2),
              Text(
                label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                textAlign: TextAlign.center,
                style: TextStyle(
                  fontSize: 11,
                  fontWeight: isSelected ? FontWeight.w700 : FontWeight.w500,
                  color: isSelected
                      ? (isDark ? TopkolikTheme.textPrimaryDark : TopkolikTheme.pitchGreen)
                      : (isDark ? TopkolikTheme.textSecondaryDark : TopkolikTheme.textSecondaryLight),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildCommentCard(ArenaComment comment, bool isDark, TopkolikLocalizations l10n) {
    final isQuarantined = comment.moderationState == 'quarantined';

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: isDark ? TopkolikTheme.darkCard : TopkolikTheme.lightCard,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
          color: isQuarantined
              ? TopkolikTheme.warningOrange.withValues(alpha: 0.6)
              : (isDark ? TopkolikTheme.darkBorder : TopkolikTheme.lightBorder),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              CircleAvatar(
                radius: 14,
                backgroundColor: TopkolikTheme.pitchGreen,
                child: Text(
                  comment.authorName.isNotEmpty ? comment.authorName[0].toUpperCase() : 'T',
                  style: const TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.bold,
                    color: Colors.white,
                  ),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      comment.authorName,
                      style: TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                        color: isDark ? TopkolikTheme.textPrimaryDark : TopkolikTheme.textPrimaryLight,
                      ),
                    ),
                    Text(
                      '${comment.createdAt.hour.toString().padLeft(2, '0')}:${comment.createdAt.minute.toString().padLeft(2, '0')}',
                      style: TextStyle(
                        fontSize: 10,
                        color: isDark ? TopkolikTheme.textMutedDark : TopkolikTheme.textMutedLight,
                      ),
                    ),
                  ],
                ),
              ),
              PopupMenuButton<String>(
                icon: Icon(
                  Icons.more_horiz_rounded,
                  size: 18,
                  color: isDark ? TopkolikTheme.textMutedDark : TopkolikTheme.textMutedLight,
                ),
                color: isDark ? TopkolikTheme.darkSurface : Colors.white,
                onSelected: (val) {
                  if (val == 'report') {
                    _openReportDialog(comment.id);
                  } else if (val == 'block') {
                    widget.onBlockUser(comment.userId);
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(content: Text('${comment.authorName} engellendi.')),
                    );
                  }
                },
                itemBuilder: (_) => [
                  PopupMenuItem(
                    value: 'report',
                    child: Text(l10n.translate('report'), style: const TextStyle(fontSize: 13)),
                  ),
                  PopupMenuItem(
                    value: 'block',
                    child: Text(l10n.translate('block_user'), style: const TextStyle(fontSize: 13)),
                  ),
                ],
              ),
            ],
          ),
          const SizedBox(height: 8),
          if (isQuarantined)
            Container(
              margin: const EdgeInsets.only(bottom: 6),
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
              decoration: BoxDecoration(
                color: TopkolikTheme.warningOrange.withValues(alpha: 0.15),
                borderRadius: BorderRadius.circular(6),
              ),
              child: Text(
                l10n.translate('quarantined_notice'),
                style: const TextStyle(
                  fontSize: 11,
                  fontStyle: FontStyle.italic,
                  color: TopkolikTheme.warningOrange,
                ),
              ),
            ),
          Text(
            comment.body,
            style: TextStyle(
              fontSize: 13,
              height: 1.35,
              color: isDark ? TopkolikTheme.textPrimaryDark : TopkolikTheme.textPrimaryLight,
            ),
          ),
          const SizedBox(height: 6),
          Align(
            alignment: Alignment.centerRight,
            child: InkWell(
              onTap: () {
                setState(() {
                  _replyingToCommentId = comment.id;
                  _replyingToAuthorName = comment.authorName;
                });
                _focusNode.requestFocus();
              },
              borderRadius: BorderRadius.circular(6),
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(
                      Icons.reply_rounded,
                      size: 13,
                      color: isDark ? TopkolikTheme.textSecondaryDark : TopkolikTheme.textSecondaryLight,
                    ),
                    const SizedBox(width: 4),
                    Text(
                      'Yanıtla',
                      style: TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w600,
                        color: isDark ? TopkolikTheme.textSecondaryDark : TopkolikTheme.textSecondaryLight,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
