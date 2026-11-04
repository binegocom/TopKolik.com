import 'package:flutter/material.dart';
import '../../../../core/painters/stadium_painters.dart';
import '../../../../core/theme/topkolik_theme.dart';
import '../../../../core/widgets/motion_widgets.dart';
import '../../../../core/models/topkolik_domain_models.dart';

class TacticalPitchBoardView extends StatefulWidget {
  final TacticalLineup lineup;
  final Function(String formation) onFormationChanged;
  final Function(String mentality) onMentalityChanged;
  final Function(int starterIdx, int benchIdx) onPlayerSubstituted;

  const TacticalPitchBoardView({
    super.key,
    required this.lineup,
    required this.onFormationChanged,
    required this.onMentalityChanged,
    required this.onPlayerSubstituted,
  });

  @override
  State<TacticalPitchBoardView> createState() => _TacticalPitchBoardViewState();
}

class _TacticalPitchBoardViewState extends State<TacticalPitchBoardView> {
  int? _selectedStarterSlot;
  int? _selectedBenchSlot;
  bool _isShared = false;

  void _handleStarterTap(int slotIndex) {
    setState(() {
      if (_selectedStarterSlot == slotIndex) {
        _selectedStarterSlot = null;
      } else {
        _selectedStarterSlot = slotIndex;
        if (_selectedBenchSlot != null) {
          widget.onPlayerSubstituted(_selectedStarterSlot!, _selectedBenchSlot!);
          _selectedStarterSlot = null;
          _selectedBenchSlot = null;
        }
      }
    });
  }

  void _handleBenchTap(int slotIndex) {
    setState(() {
      if (_selectedBenchSlot == slotIndex) {
        _selectedBenchSlot = null;
      } else {
        _selectedBenchSlot = slotIndex;
        if (_selectedStarterSlot != null) {
          widget.onPlayerSubstituted(_selectedStarterSlot!, _selectedBenchSlot!);
          _selectedStarterSlot = null;
          _selectedBenchSlot = null;
        }
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    const accentColor = TopkolikTheme.electricAccent;

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 90),
      physics: const BouncingScrollPhysics(),
      children: [
        // Header
        GlassContainer(
          padding: const EdgeInsets.all(16),
          borderRadius: 22,
          borderColor: TopkolikTheme.turfNeon.withValues(alpha: 0.35),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: const [
                  Icon(Icons.sports_soccer_rounded, color: TopkolikTheme.turfNeon, size: 20),
                  SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      'İLK 11 TAKTİK TAHTASI & KADRO KURUCU',
                      style: TextStyle(
                        fontWeight: FontWeight.w900,
                        fontSize: 13,
                        color: TopkolikTheme.turfNeon,
                      ),
                    ),
                  ),
                ],
              ),
              if (_selectedStarterSlot != null || _selectedBenchSlot != null) ...[
                const SizedBox(height: 8),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                  decoration: BoxDecoration(
                    color: TopkolikTheme.derbyGold.withValues(alpha: 0.22),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: const Text(
                    '⚡ Değiştirmek için 2. oyuncuya dokun',
                    style: TextStyle(
                      color: TopkolikTheme.derbyGold,
                      fontWeight: FontWeight.w800,
                      fontSize: 10.5,
                    ),
                  ),
                ),
              ],
              const SizedBox(height: 12),

              // Formation & Mentality Bar (Responsive Wrap to guarantee zero overflow)
              Wrap(
                spacing: 8,
                runSpacing: 8,
                crossAxisAlignment: WrapCrossAlignment.center,
                alignment: WrapAlignment.spaceBetween,
                children: [
                  Row(
                    mainAxisSize: MainAxisSize.min,
                    children: ['4-2-3-1', '4-3-3', '3-5-2'].map((form) {
                      final isSel = widget.lineup.formation == form;
                      return Padding(
                        padding: const EdgeInsets.only(right: 6),
                        child: PressableScale(
                          onTap: () => widget.onFormationChanged(form),
                          child: Container(
                            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                            decoration: BoxDecoration(
                              color: isSel ? TopkolikTheme.turfNeon : TopkolikTheme.obsidianBg,
                              borderRadius: BorderRadius.circular(10),
                            ),
                            child: Text(
                              form,
                              style: TextStyle(
                                color: isSel ? Colors.black : Colors.white,
                                fontWeight: FontWeight.w900,
                                fontSize: 11.5,
                              ),
                            ),
                          ),
                        ),
                      );
                    }).toList(),
                  ),
                  PressableScale(
                    onTap: () {
                      const modes = ['Önde Şok Baskı', 'Dengeli Pas Oyunu', 'Hızlı Geçiş & Kontra'];
                      final nextIdx = (modes.indexOf(widget.lineup.mentality) + 1) % modes.length;
                      widget.onMentalityChanged(modes[nextIdx]);
                    },
                    child: Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                      decoration: BoxDecoration(
                        color: TopkolikTheme.derbyGold.withValues(alpha: 0.18),
                        borderRadius: BorderRadius.circular(10),
                        border: Border.all(
                          color: TopkolikTheme.derbyGold.withValues(alpha: 0.6),
                        ),
                      ),
                      child: Text(
                        '⚡ ${widget.lineup.mentality}',
                        style: const TextStyle(
                          color: TopkolikTheme.derbyGold,
                          fontWeight: FontWeight.w800,
                          fontSize: 11,
                        ),
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 14),

              // Interactive 2D Pitch Board
              RepaintBoundary(
                child: SizedBox(
                  height: 350,
                  width: double.infinity,
                  child: LayoutBuilder(
                    builder: (context, constraints) {
                      final w = constraints.maxWidth;
                      final h = constraints.maxHeight;

                      return Stack(
                        clipBehavior: Clip.none,
                        children: [
                          Positioned.fill(
                            child: CustomPaint(
                              painter: TacticalPitchPainter(accentColor: accentColor),
                            ),
                          ),
                          ...widget.lineup.starters.map((slot) {
                            final isSelected = _selectedStarterSlot == slot.slotIndex;
                            final left = (slot.xRatio * w) - 34;
                            final top = (slot.yRatio * h) - 28;

                            return AnimatedPositioned(
                              key: ValueKey('pitch-slot-${slot.slotIndex}'),
                              duration: const Duration(milliseconds: 300),
                              curve: Curves.easeOutBack,
                              left: left.clamp(4.0, w - 72.0),
                              top: top.clamp(4.0, h - 62.0),
                              child: GestureDetector(
                                onTap: () => _handleStarterTap(slot.slotIndex),
                                child: SizedBox(
                                  width: 68,
                                  child: Column(
                                    mainAxisSize: MainAxisSize.min,
                                    children: [
                                      Container(
                                        width: 36,
                                        height: 36,
                                        decoration: BoxDecoration(
                                          shape: BoxShape.circle,
                                          color: TopkolikTheme.parseHex(slot.clubPrimaryHex),
                                          border: Border.all(
                                            color: isSelected ? TopkolikTheme.turfNeon : Colors.white,
                                            width: isSelected ? 2.8 : 1.6,
                                          ),
                                          boxShadow: [
                                            BoxShadow(
                                              color: isSelected
                                                  ? TopkolikTheme.turfNeon
                                                  : Colors.black.withValues(alpha: 0.45),
                                              blurRadius: isSelected ? 12 : 6,
                                            ),
                                          ],
                                        ),
                                        alignment: Alignment.center,
                                        child: Text(
                                          '${slot.shirtNumber}',
                                          style: const TextStyle(
                                            color: Colors.white,
                                            fontWeight: FontWeight.w900,
                                            fontSize: 12,
                                          ),
                                        ),
                                      ),
                                      const SizedBox(height: 3),
                                      Container(
                                        padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 2),
                                        decoration: BoxDecoration(
                                          color: Colors.black.withValues(alpha: 0.75),
                                          borderRadius: BorderRadius.circular(6),
                                        ),
                                        child: Text(
                                          slot.playerName.split(' ').last,
                                          maxLines: 1,
                                          overflow: TextOverflow.ellipsis,
                                          style: const TextStyle(
                                            fontSize: 10,
                                            fontWeight: FontWeight.w800,
                                            color: Colors.white,
                                          ),
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                              ),
                            );
                          }),
                        ],
                      );
                    },
                  ),
                ),
              ),

              const SizedBox(height: 12),
              const Text(
                'YEDEK KULÜBESİ (Oyuncu değiştirmek için dokun)',
                style: TextStyle(
                  color: TopkolikTheme.textSecondary,
                  fontWeight: FontWeight.w800,
                  fontSize: 11,
                ),
              ),
              const SizedBox(height: 8),

              // Bench list
              SizedBox(
                height: 44,
                child: ListView.separated(
                  scrollDirection: Axis.horizontal,
                  itemCount: widget.lineup.bench.length,
                  separatorBuilder: (_, _) => const SizedBox(width: 8),
                  itemBuilder: (context, idx) {
                    final b = widget.lineup.bench[idx];
                    final isSel = _selectedBenchSlot == b.slotIndex;
                    return PressableScale(
                      onTap: () => _handleBenchTap(b.slotIndex),
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                        decoration: BoxDecoration(
                          color: isSel ? TopkolikTheme.turfNeon.withValues(alpha: 0.22) : TopkolikTheme.obsidianBg,
                          borderRadius: BorderRadius.circular(12),
                          border: Border.all(
                            color: isSel ? TopkolikTheme.turfNeon : Colors.white.withValues(alpha: 0.12),
                          ),
                        ),
                        alignment: Alignment.center,
                        child: Text(
                          '#${b.shirtNumber} ${b.playerName}',
                          style: TextStyle(
                            fontWeight: FontWeight.w800,
                            fontSize: 12,
                            color: isSel ? TopkolikTheme.turfNeon : Colors.white,
                          ),
                        ),
                      ),
                    );
                  },
                ),
              ),
              const SizedBox(height: 14),

              // Share button
              SizedBox(
                width: double.infinity,
                child: PressableScale(
                  onTap: () {
                    setState(() => _isShared = true);
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(
                        content: Text('Taktik kadronuz maç tribünü akışında paylaşıldı! (+50 XP)'),
                        backgroundColor: TopkolikTheme.pitchGreen,
                        duration: Duration(seconds: 2),
                      ),
                    );
                  },
                  child: Container(
                    padding: const EdgeInsets.symmetric(vertical: 12),
                    decoration: BoxDecoration(
                      color: _isShared ? TopkolikTheme.pitchGreen : TopkolikTheme.turfNeon,
                      borderRadius: BorderRadius.circular(14),
                    ),
                    alignment: Alignment.center,
                    child: Text(
                      _isShared ? '✓ Akışta Paylaşıldı (+50 XP)' : '🚀 Kadromu Tribün Akışında Paylaş (+50 XP)',
                      style: TextStyle(
                        color: _isShared ? Colors.white : Colors.black,
                        fontWeight: FontWeight.w900,
                        fontSize: 13,
                      ),
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
