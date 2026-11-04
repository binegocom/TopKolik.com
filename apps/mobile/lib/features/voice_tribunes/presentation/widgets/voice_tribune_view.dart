import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import '../../../../core/painters/stadium_painters.dart';
import '../../../../core/theme/topkolik_theme.dart';
import '../../../../core/widgets/motion_widgets.dart';
import '../../../../core/models/topkolik_domain_models.dart';

class VoiceTribuneView extends StatefulWidget {
  final List<VoiceTribuneRoom> rooms;
  final Function(String title, String phaseTag) onCreateRoom;
  final Function(int roomId, String action, String? emoji) onTriggerAction;

  const VoiceTribuneView({
    super.key,
    required this.rooms,
    required this.onCreateRoom,
    required this.onTriggerAction,
  });

  @override
  State<VoiceTribuneView> createState() => _VoiceTribuneViewState();
}

class _VoiceTribuneViewState extends State<VoiceTribuneView>
    with SingleTickerProviderStateMixin {
  late final AnimationController _waveController;
  final TextEditingController _titleController = TextEditingController();
  String _selectedPhase = 'MAÇ ANINDA CANLI';

  @override
  void initState() {
    super.initState();
    _waveController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1400),
    )..repeat();
  }

  @override
  void dispose() {
    _waveController.dispose();
    _titleController.dispose();
    super.dispose();
  }

  void _showCreateModal(BuildContext context) {
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) {
        return StatefulBuilder(
          builder: (modalCtx, setModalState) {
            return Padding(
              padding: EdgeInsets.only(
                bottom: MediaQuery.viewInsetsOf(modalCtx).bottom,
              ),
              child: Container(
                padding: const EdgeInsets.all(20),
                decoration: BoxDecoration(
                  color: TopkolikTheme.obsidianBg,
                  borderRadius: const BorderRadius.vertical(top: Radius.circular(24)),
                  border: Border.all(color: Colors.white.withValues(alpha: 0.12)),
                ),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      '🎙️ YENİ SESLİ TRİBÜN ODASI AÇ (+40 XP)',
                      style: TextStyle(
                        fontWeight: FontWeight.w900,
                        fontSize: 14,
                        color: TopkolikTheme.turfNeon,
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: _titleController,
                      decoration: InputDecoration(
                        hintText: 'Örn: Derbi 2. Yarı Taktik ve Hakem Analizi',
                        filled: true,
                        fillColor: TopkolikTheme.stadiumSurface,
                        border: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(14),
                          borderSide: BorderSide.none,
                        ),
                      ),
                    ),
                    const SizedBox(height: 12),
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: ['MAÇ ÖNÜ ISINMA', 'MAÇ ANINDA CANLI', 'MAÇ SONU 3. DEVRE'].map((tag) {
                        final isSel = _selectedPhase == tag;
                        return GestureDetector(
                          onTap: () => setModalState(() => _selectedPhase = tag),
                          child: Container(
                            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                            decoration: BoxDecoration(
                              color: isSel
                                  ? TopkolikTheme.turfNeon.withValues(alpha: 0.2)
                                  : TopkolikTheme.stadiumSurface,
                              borderRadius: BorderRadius.circular(10),
                              border: Border.all(
                                color: isSel ? TopkolikTheme.turfNeon : Colors.white.withValues(alpha: 0.1),
                              ),
                            ),
                            child: Text(
                              tag,
                              style: TextStyle(
                                fontSize: 11,
                                fontWeight: FontWeight.w800,
                                color: isSel ? TopkolikTheme.turfNeon : Colors.white,
                              ),
                            ),
                          ),
                        );
                      }).toList(),
                    ),
                    const SizedBox(height: 16),
                    SizedBox(
                      width: double.infinity,
                      child: PressableScale(
                        onTap: () {
                          final title = _titleController.text.trim();
                          if (title.isEmpty) return;
                          widget.onCreateRoom(title, _selectedPhase);
                          _titleController.clear();
                          Navigator.of(modalCtx).pop();
                        },
                        child: Container(
                          padding: const EdgeInsets.symmetric(vertical: 13),
                          decoration: BoxDecoration(
                            color: TopkolikTheme.turfNeon,
                            borderRadius: BorderRadius.circular(14),
                          ),
                          alignment: Alignment.center,
                          child: const Text(
                            'Canlı Tribün Başlat',
                            style: TextStyle(
                              color: Colors.black,
                              fontWeight: FontWeight.w900,
                              fontSize: 14,
                            ),
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            );
          },
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    final activeJoined = widget.rooms.firstWhere(
      (r) => r.isJoined,
      orElse: () => widget.rooms.isNotEmpty ? widget.rooms.first : const VoiceTribuneRoom(
        id: 0,
        fixtureId: 1,
        title: 'Canlı Yayın',
        phaseTag: 'MAÇ ANINDA CANLI',
        listenersCount: 1,
        hostName: 'Sunucu',
      ),
    );

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 90),
      physics: const BouncingScrollPhysics(),
      children: [
        // Header
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            const Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '🎙️ SESLİ TRİBÜN MEYDANI',
                    style: TextStyle(fontWeight: FontWeight.w900, fontSize: 15),
                  ),
                  SizedBox(height: 2),
                  Text(
                    'Maç önü, maç anı ve maç sonu 3. devre sesli taraftar odaları',
                    style: TextStyle(color: TopkolikTheme.textSecondary, fontSize: 12),
                  ),
                ],
              ),
            ),
            PressableScale(
              onTap: () => _showCreateModal(context),
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                decoration: BoxDecoration(
                  color: TopkolikTheme.turfNeon,
                  borderRadius: BorderRadius.circular(12),
                ),
                child: const Text(
                  '+ Oda Aç',
                  style: TextStyle(
                    color: Colors.black,
                    fontWeight: FontWeight.w900,
                    fontSize: 12,
                  ),
                ),
              ),
            ),
          ],
        ),
        const SizedBox(height: 14),

        // Featured Active Room Stage
        if (widget.rooms.isNotEmpty) _buildActiveVoiceStage(activeJoined),

        const SizedBox(height: 18),
        const Text(
          'TÜM AKTİF SESLİ TRİBÜNLER',
          style: TextStyle(fontWeight: FontWeight.w900, fontSize: 13),
        ),
        const SizedBox(height: 10),

        ...widget.rooms.map((room) => Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: _buildRoomCard(room),
            )),
      ],
    );
  }

  Widget _buildActiveVoiceStage(VoiceTribuneRoom room) {
    return GlassContainer(
      padding: const EdgeInsets.all(16),
      borderRadius: 22,
      borderColor: TopkolikTheme.liveCrimson.withValues(alpha: 0.45),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(
                  color: TopkolikTheme.liveCrimson.withValues(alpha: 0.22),
                  borderRadius: BorderRadius.circular(99),
                ),
                child: Text(
                  '● ${room.phaseTag}',
                  style: const TextStyle(
                    color: TopkolikTheme.liveCrimson,
                    fontWeight: FontWeight.w900,
                    fontSize: 11,
                  ),
                ),
              ),
              const Spacer(),
              Text(
                '🎧 ${room.listenersCount} Dinleyici',
                style: const TextStyle(
                  color: TopkolikTheme.turfNeon,
                  fontWeight: FontWeight.w800,
                  fontSize: 12,
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Text(
            room.title,
            style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 15),
          ),
          const SizedBox(height: 12),

          // Live Equalizer Waveform (AudioWaveformPainter)
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            decoration: BoxDecoration(
              color: TopkolikTheme.obsidianBg.withValues(alpha: 0.7),
              borderRadius: BorderRadius.circular(14),
            ),
            child: Row(
              children: [
                const Icon(Icons.graphic_eq_rounded, color: TopkolikTheme.turfNeon, size: 20),
                const SizedBox(width: 10),
                Expanded(
                  child: RepaintBoundary(
                    child: SizedBox(
                      height: 28,
                      child: AnimatedBuilder(
                        animation: _waveController,
                        builder: (context, _) {
                          return CustomPaint(
                            painter: AudioWaveformPainter(
                              level: 0.88,
                              phase: _waveController.value,
                              color: TopkolikTheme.turfNeon,
                            ),
                          );
                        },
                      ),
                    ),
                  ),
                ),
                const SizedBox(width: 10),
                const Text('🔥 ⚽ 🦁', style: TextStyle(fontSize: 15)),
              ],
            ),
          ),
          const SizedBox(height: 14),

          // Controls (Mic toggle, Hand raise)
          Row(
            children: [
              Expanded(
                child: PressableScale(
                  onTap: () => widget.onTriggerAction(room.id, 'toggle-mic', null),
                  child: Container(
                    padding: const EdgeInsets.symmetric(vertical: 11),
                    decoration: BoxDecoration(
                      color: room.isMicMuted ? TopkolikTheme.obsidianBg : TopkolikTheme.turfNeon,
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(
                        color: room.isMicMuted ? Colors.white.withValues(alpha: 0.15) : TopkolikTheme.turfNeon,
                      ),
                    ),
                    alignment: Alignment.center,
                    child: Text(
                      room.isMicMuted ? '🎙️ Mikrofonu Aç' : '🔊 Konuşuyorsun',
                      style: TextStyle(
                        color: room.isMicMuted ? Colors.white : Colors.black,
                        fontWeight: FontWeight.w900,
                        fontSize: 12,
                      ),
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: PressableScale(
                  onTap: () => widget.onTriggerAction(room.id, 'toggle-hand', null),
                  child: Container(
                    padding: const EdgeInsets.symmetric(vertical: 11),
                    decoration: BoxDecoration(
                      color: room.isHandRaised
                          ? TopkolikTheme.derbyGold.withValues(alpha: 0.25)
                          : TopkolikTheme.obsidianBg,
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(
                        color: room.isHandRaised ? TopkolikTheme.derbyGold : Colors.white.withValues(alpha: 0.15),
                      ),
                    ),
                    alignment: Alignment.center,
                    child: Text(
                      room.isHandRaised ? '✋ Söz İstendi' : '✋ Söz İste',
                      style: TextStyle(
                        color: room.isHandRaised ? TopkolikTheme.derbyGold : Colors.white,
                        fontWeight: FontWeight.w800,
                        fontSize: 12,
                      ),
                    ),
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceEvenly,
            children: ['🔥', '⚽', '👏', '🦁', '💛💙'].map((emoji) {
              return PressableScale(
                onTap: () => widget.onTriggerAction(room.id, 'react', emoji),
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
                  decoration: BoxDecoration(
                    color: TopkolikTheme.obsidianBg.withValues(alpha: 0.65),
                    borderRadius: BorderRadius.circular(99),
                  ),
                  child: Text(emoji, style: const TextStyle(fontSize: 16)),
                ),
              );
            }).toList(),
          ),
        ],
      ),
    ).animate().fadeIn(duration: 250.ms);
  }

  Widget _buildRoomCard(VoiceTribuneRoom room) {
    return GlassContainer(
      padding: const EdgeInsets.all(14),
      borderRadius: 18,
      child: Row(
        children: [
          ClubCrestBadge(
            shortName: room.clubName != null ? room.clubName!.substring(0, 2).toUpperCase() : 'TK',
            primaryHex: room.clubId == 1 ? '#A6192E' : '#002D72',
            secondaryHex: room.clubId == 1 ? '#FFD700' : '#FFFF00',
            size: 42,
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  room.phaseTag,
                  style: const TextStyle(
                    color: TopkolikTheme.turfNeon,
                    fontWeight: FontWeight.w800,
                    fontSize: 10.5,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  room.title,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13),
                ),
                const SizedBox(height: 4),
                Text(
                  '🎧 ${room.listenersCount} Dinleyici • ${room.hostName}',
                  style: const TextStyle(color: TopkolikTheme.textSecondary, fontSize: 11),
                ),
              ],
            ),
          ),
          const SizedBox(width: 10),
          PressableScale(
            onTap: () => widget.onTriggerAction(room.id, 'toggle-join', null),
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
              decoration: BoxDecoration(
                color: room.isJoined
                    ? TopkolikTheme.turfNeon.withValues(alpha: 0.2)
                    : TopkolikTheme.turfNeon,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: TopkolikTheme.turfNeon),
              ),
              child: Text(
                room.isJoined ? 'Odadasın' : 'Katıl',
                style: TextStyle(
                  color: room.isJoined ? TopkolikTheme.turfNeon : Colors.black,
                  fontWeight: FontWeight.w900,
                  fontSize: 12,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
