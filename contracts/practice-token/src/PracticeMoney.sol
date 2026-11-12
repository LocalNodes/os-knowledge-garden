// SPDX-License-Identifier: MIT
pragma solidity 0.8.24;

import {ERC20} from "@openzeppelin/contracts/token/ERC20/ERC20.sol";

/// @title Practice Money (PRACTICE)
/// @notice Practice money for group funds on test networks. It has no value
/// and is never deployed on a network that carries real money.
/// @dev No owner, no admin, no pause, no upgrade. Anyone may call fund(); it
/// gives PRACTICE_AMOUNT once to an address that holds contract code (a group
/// fund), never to a plain wallet. No constructor arguments, so a CREATE2
/// deploy with a fixed salt lands at the same address on every network.
contract PracticeMoney is ERC20 {
    /// @notice What each group fund gets, once: 1,000 with 18 decimals.
    uint256 public constant PRACTICE_AMOUNT = 1_000 * 10 ** 18;

    /// @notice Whether an address has had its practice money.
    mapping(address => bool) public funded;

    /// @notice A group fund got its practice money.
    event Funded(address indexed fund, uint256 amount);

    /// @notice The address already had its practice money.
    error AlreadyFunded(address fund);

    /// @notice The address holds no contract code, so it is not a group fund.
    error NotAContract(address fund);

    /// @notice The token cannot fund itself: a balance held by the token
    /// contract could never be moved again.
    error TokenItself();

    /// @notice The token's name and symbol. Changing either changes the
    /// CREATE2 address.
    string public constant NAME = "Practice Money";
    string public constant SYMBOL = "PRACTICE";

    constructor() ERC20(NAME, SYMBOL) {}

    /// @notice Gives a group fund its practice money, once.
    /// @param groupFund The deployed group fund (a Safe) to fund.
    function fund(address groupFund) external {
        if (groupFund == address(this)) revert TokenItself();
        if (funded[groupFund]) revert AlreadyFunded(groupFund);
        if (groupFund.code.length == 0) revert NotAContract(groupFund);
        funded[groupFund] = true;
        _mint(groupFund, PRACTICE_AMOUNT);
        emit Funded(groupFund, PRACTICE_AMOUNT);
    }
}
